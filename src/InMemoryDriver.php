<?php

namespace Amp\Http\Server\Session;

use Amp\Cache\ArrayCache;
use Amp\Cache\Cache;
use Amp\Promise;
use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use ParagonIE\ConstantTime\Base64UrlSafe;
use function Amp\call;

/**
 * This driver saves all sessions in memory, mainly for local development purposes.
 *
 * Locking happens via LocalMutex, so it won't work correctly with multiple processes.
 */
class InMemoryDriver implements Driver
{
    const ID_REGEXP = '/^[A-Za-z0-9_\-]{48}$/';
    const ID_BYTES = 36; // divisible by three to not waste chars with "=" and simplify regexp.

    const DEFAULT_TTL = 3600;

    /** @var Cache */
    private $cache;

    /** @var LocalMutex[] */
    private $mutex = [];

    /** @var Lock[] */
    private $locks = [];

    /** @var Serializer */
    private $serializer;

    /** @var int */
    private $ttl;

    public function __construct(Serializer $serializer = null, int $ttl = self::DEFAULT_TTL)
    {
        $this->ttl = $ttl;
        $this->cache = new ArrayCache();
        $this->serializer = $serializer ?? new CompressingSerializeSerializer;
    }

    /** @inheritdoc */
    protected function generate(): string
    {
        return Base64UrlSafe::encode(\random_bytes(self::ID_BYTES));
    }

    /** @inheritdoc */
    public function validate(string $id): bool
    {
        return \preg_match(self::ID_REGEXP, $id);
    }

    /** @inheritdoc */
    public function create(): Promise
    {
        return call(function () {
            $id = $this->generate();
            yield $this->lock($id);
            return $id;
        });
    }

    /** @inheritdoc */
    public function save(string $id, array $data): Promise
    {
        return call(function () use ($id, $data) {
            if (empty($data)) {
                try {
                    yield $this->cache->delete($id);
                } catch (\Throwable $error) {
                    throw new SessionException("Couldn't delete session '{$id}''", 0, $error);
                }

                return;
            }

            try {
                $serializedData = $this->serializer->serialize($data);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't serialize data for session '{$id}'", 0, $error);
            }

            try {
                yield $this->cache->set($id, $serializedData, $this->ttl);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't persist data for session '{$id}'", 0, $error);
            }
        });
    }

    /** @inheritdoc */
    public function read(string $id): Promise
    {
        return call(function () use ($id) {
            try {
                $result = yield $this->cache->get($id);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't read data for session '${id}'", 0, $error);
            }

            if ($result === null) {
                return [];
            }

            try {
                $data = $this->serializer->unserialize($result);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't read data for session '${id}'", 0, $error);
            }

            try {
                // Cache::set() can only be used here, because we know the implementation is synchronous,
                // otherwise we'd need locking
                yield $this->cache->set($id, $result, $this->ttl);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
            }

            return $data;
        });
    }

    /** @inheritdoc */
    public function lock(string $id): Promise
    {
        return call(function () use ($id) {
            if (!isset($this->mutex[$id])) {
                $this->mutex[$id] = new LocalMutex;
            }

            try {
                $this->locks[$id] = yield $this->mutex[$id]->acquire();
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't acquire lock for session '${id}'", 0, $error);
            }

            return $this->read($id);
        });
    }

    /** @inheritdoc */
    public function unlock(string $id): Promise
    {
        return call(function () use ($id) {
            if (!isset($this->locks[$id])) {
                throw new \Error("Couldn't unlock session '${id}', because no lock exists");
            }

            try {
                $lock = $this->locks[$id];
                unset($this->locks[$id]);
                $lock->release();

                if (!isset($this->locks[$id])) {
                    unset($this->mutex[$id]);
                }
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't unlock session '${id}'", 0, $error);
            }
        });
    }
}
