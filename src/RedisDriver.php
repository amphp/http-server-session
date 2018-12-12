<?php

namespace Amp\Http\Server\Session;

use Amp\Loop;
use Amp\Promise;
use Amp\Redis\Client;
use Amp\Success;
use Kelunik\RedisMutex\Mutex;
use ParagonIE\ConstantTime\Base64UrlSafe;
use function Amp\call;

class RedisDriver implements Driver
{
    const ID_REGEXP = '/^[A-Za-z0-9_\-]{48}$/';
    const ID_BYTES = 36; // divisible by three to not waste chars with "=" and simplify regexp.

    const FLAG_COMPRESSED = 1;

    const COMPRESSION_THRESHOLD = 256;

    const DEFAULT_TTL = 3600;

    /** @var Client */
    private $client;

    /** @var Mutex */
    private $mutex;

    /** @var string[] */
    private $locks = [];

    /** @var string Watcher ID for mutex renewals. */
    private $repeatTimer;

    /** @var string */
    private $keyPrefix;

    /**
     * @param Client $client
     * @param Mutex  $mutex
     * @param string $keyPrefix
     */
    public function __construct(Client $client, Mutex $mutex, string $keyPrefix = 'sess:')
    {
        $this->client = $client;
        $this->mutex = $mutex;
        $this->keyPrefix = $keyPrefix;

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
     * @return Client Redis client being used by the driver.
     */
    final protected function getClient(): Client
    {
        return $this->client;
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
                    yield $this->client->del($this->keyPrefix . $id);
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
                yield $this->client->set($this->keyPrefix . $id, $data, $ttl ?? self::DEFAULT_TTL);
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
                yield $this->client->expire($this->keyPrefix . $id, $ttl ?? self::DEFAULT_TTL);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
            }

            return $data;
        });
    }

    /** @inheritdoc */
    public function lock(string $id): Promise
    {
        $token = Base64UrlSafe::encode(\random_bytes(16));

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
