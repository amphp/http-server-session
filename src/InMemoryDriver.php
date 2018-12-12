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

    const FLAG_COMPRESSED = 1;

    const COMPRESSION_THRESHOLD = 256;

    const DEFAULT_TTL = 3600;

    /** @var Cache */
    private $cache;

    /** @var LocalMutex[] */
    private $mutex = [];

    /** @var Lock[] */
    private $locks = [];

    public function __construct()
    {
        $this->cache = new ArrayCache();
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
    public function save(string $id, array $data, int $ttl = null): Promise
    {
        return call(function () use ($id, $data, $ttl) {
            if (empty($data) || $ttl < 0) {
                try {
                    yield $this->cache->delete($id);
                } catch (\Throwable $error) {
                    throw new SessionException("Couldn't delete session '{$id}''", 0, $error);
                }

                return;
            }

            try {
                $data = \serialize([$ttl, $data]);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't serialize data for session '{$id}'", 0, $error);
            }

            $flags = 0;

            if (\strlen($data) > self::COMPRESSION_THRESHOLD) {
                $data = \gzdeflate($data, 1);
                $flags |= self::FLAG_COMPRESSED;
            }

            $data = \chr($flags & 0xff) . $data;

            try {
                yield $this->cache->set($id, $data, $ttl ?? self::DEFAULT_TTL);
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

            if ($result === null || $result === '') {
                return null;
            }

            $firstByte = \ord($result[0]);
            $result = \substr($result, 1);

            if ($firstByte & self::FLAG_COMPRESSED) {
                $result = \gzinflate($result);
            }

            list($ttl, $data) = \unserialize($result, ['allowed_classes' => true]);

            try {
                // Cache::set() can only be used here, because we know the implementation is synchronous,
                // otherwise we'd need locking
                yield $this->cache->set($id, yield $this->cache->get($id), $ttl ?? self::DEFAULT_TTL);
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
