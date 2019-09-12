<?php

namespace Amp\Http\Server\Session;

use Amp\Loop;
use Amp\Promise;
use Amp\Redis\Mutex\Mutex;
use Amp\Redis\Redis;
use Amp\Redis\SetOptions;
use Amp\Success;
use function Amp\call;

class RedisStorage implements Storage
{
    public const DEFAULT_TTL = 3600;

    private const ID_REGEXP = '/^[A-Za-z0-9_\-]{48}$/';
    private const ID_BYTES = 36; // divisible by three to not waste chars with "=" and simplify regexp.

    /** @var Redis */
    private $client;

    /** @var Mutex */
    private $mutex;

    /** @var string[] */
    private $locks = [];

    /** @var string Watcher ID for mutex renewals. */
    private $repeatTimer;

    /** @var string */
    private $keyPrefix;

    /** @var int */
    private $ttl;

    /** @var Serializer */
    private $serializer;

    /** @var IdGenerator */
    private $idGenerator;

    /**
     * @param Redis            $client
     * @param Mutex            $mutex
     * @param Serializer|null  $serializer
     * @param IdGenerator|null $idGenerator
     * @param int              $ttl
     * @param string           $keyPrefix
     */
    public function __construct(
        Redis $client,
        Mutex $mutex,
        ?Serializer $serializer = null,
        ?IdGenerator $idGenerator = null,
        int $ttl = self::DEFAULT_TTL,
        string $keyPrefix = 'sess:'
    ) {
        $this->client = $client;
        $this->mutex = $mutex;
        $this->keyPrefix = $keyPrefix;
        $this->ttl = $ttl;
        $this->serializer = $serializer ?? new CompressingSerializeSerializer;
        $this->idGenerator = $idGenerator ?? new DefaultIdGenerator;

        $locks = &$this->locks;

        $this->repeatTimer = Loop::repeat($this->mutex->getTtl() / 2, static function () use (&$locks, $mutex) {
            foreach ($locks as $id => $token) {
                $mutex->renew($id, $token);
            }
        });

        Loop::unreference($this->repeatTimer);
    }

    public function __destruct()
    {
        Loop::cancel($this->repeatTimer);
    }

    final protected function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    /**
     * @return Redis Redis client being used by the driver.
     */
    final protected function getClient(): Redis
    {
        return $this->client;
    }

    /** @inheritdoc */
    public function validate(string $id): bool
    {
        return $this->idGenerator->validate($id);
    }

    /** @inheritdoc */
    public function create(): Promise
    {
        return call(function () {
            $id = $this->idGenerator->generate();
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
                    yield $this->client->delete($this->keyPrefix . $id);
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
                $options = (new SetOptions)->withTtl($this->ttl);
                yield $this->client->set($this->keyPrefix . $id, $serializedData, $options);
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
                $result = yield $this->client->get($this->keyPrefix . $id);
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
                yield $this->client->expire($this->keyPrefix . $id, $this->ttl);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
            }

            return $data;
        });
    }

    /** @inheritdoc */
    public function lock(string $id): Promise
    {
        $token = \base64_encode(\random_bytes(16));

        return call(function () use ($id, $token) {
            try {
                yield $this->mutex->lock($id, $token);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't acquire lock for session '${id}'", 0, $error);
            }

            $this->locks[$id] = $token;

            return $this->read($id);
        });
    }

    /** @inheritdoc */
    public function unlock(string $id): Promise
    {
        $token = $this->locks[$id] ?? '';

        if ($token === '') {
            return new Success;
        }

        return call(function () use ($id, $token) {
            try {
                yield $this->mutex->unlock($id, $token);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't unlock session '${id}'", 0, $error);
            }

            unset($this->locks[$id]);
        });
    }
}
