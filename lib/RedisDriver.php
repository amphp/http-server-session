<?php

namespace Aerys\Session;

use Amp\Loop;
use Amp\Promise;
use Amp\Redis\Client;
use Amp\Success;
use Kelunik\RedisMutex\Mutex;
use function Amp\call;

class RedisDriver implements Driver {
    const ID_REGEXP = '/^[A-Za-z0-9+\/]{32}$/';
    const ID_BYTES = 24; // divisible by three to not waste chars with "=" and simplify regexp.

    const COMPRESSION_THRESHOLD = 256;

    const DEFAULT_TTL = 3600;

    /** @var \Amp\Redis\Client */
    private $client;

    /** @var \Kelunik\RedisMutex\Mutex */
    private $mutex;

    /** @var string[] */
    private $locks = [];

    /** @var string Watcher ID for mutex renewals. */
    private $repeatTimer;

    /** @var int */
    private $ttl;

    /**
     * @param \Amp\Redis\Client $client
     * @param \Kelunik\RedisMutex\Mutex $mutex
     * @param int|null $ttl Use null for session-length cookies.
     */
    public function __construct(Client $client, Mutex $mutex, int $ttl = null) {
        $this->client = $client;
        $this->mutex = $mutex;
        $this->ttl = $ttl ?? -1;

        $this->repeatTimer = Loop::repeat($this->mutex->getTTL() / 2, function () {
            foreach ($this->locks as $id => $token) {
                $this->mutex->renew($id, $token);
            }
        });
    }

    public function __destruct() {
        Loop::cancel($this->repeatTimer);
    }

    /**
     * @return \Amp\Redis\Client Redis client being used by the driver.
     */
    protected function getClient(): Client {
        return $this->client;
    }

    /**
     * Generates a new random session identifier.
     *
     * @return string
     */
    protected function generate(): string {
        return \base64_encode(\random_bytes(self::ID_BYTES));
    }

    /**
     * @param string $id
     *
     * @return bool `true` if the identifier is in the expected format.
     */
    protected function validate(string $id): bool {
        return \preg_match(self::ID_REGEXP, $id);
    }

    /**
     * Creates a lock and reads the current session data.
     *
     * @return Promise Resolving to an instance of Session.
     */
    public function open(): Promise {
        return call(function () {
            $token = \bin2hex(\random_bytes(16));
            $id = $this->generate();

            try {
                yield $this->mutex->lock($id, $token);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't acquire a lock", 0, $error);
            }

            $this->locks[$id] = $token;

            return $this->read($id);
        });
    }

    /**
     * Saves and unlocks a session.
     *
     * @param string $id Session ID.
     * @param array  $data To store (an empty array is equivalent to destruction of the session).
     * @param int    $ttl Time until session expiration (always > 0).
     *
     * @return Promise Resolves with null on success.
     *
     * @throws \Error If the identifier given is invalid.
     */
    public function save(string $id, array $data, int $ttl): Promise {
        if (!$this->validate($id)) {
            throw new \Error("Invalid identifier");
        }

        return call(function () use ($id, $data, $ttl) {
            if (empty($data)) {
                try {
                    yield $this->client->del("sess:" . $id);
                } catch (\Throwable $error) {
                    throw new SessionException("Couldn't delete session", 0, $error);
                }

                return $this->unlock($id);
            } else {
                $data = \json_encode([$ttl, $data]);
                $flags = 0;

                if (\strlen($data) > self::COMPRESSION_THRESHOLD) {
                    $data = \gzdeflate($data, 1);
                    $flags |= 0x01;
                }

                $data = \chr($flags & 0xff) . $data;

                try {
                    yield $this->client->set("sess:" . $id, $data, $ttl === -1 ? self::DEFAULT_TTL : $ttl);
                } catch (\Throwable $error) {
                    throw new SessionException("Couldn't persist session data", 0, $error);
                }

                return $this->unlock($id);
            }
        });
    }

    /**
     * Regenerates a session ID.
     *
     * @param string $oldId Old session ID.
     *
     * @return Promise Resolves with the new session ID.
     *
     * @throws \Error If the identifier given is invalid.
     */
    public function regenerate(string $oldId): Promise {
        if (!$this->validate($oldId)) {
            throw new \Error("Invalid identifier");
        }

        return call(function () use ($oldId) {
            $token = \bin2hex(\random_bytes(16));
            $newId = $this->generate();

            try {
                yield $this->mutex->lock($newId, $token);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't acquire lock for new session ID", 0, $error);
            }

            $this->locks[$newId] = $token;

            yield $this->destroy($oldId);

            return $newId;
        });
    }

    /**
     * Destroys the session with the given identifier.
     *
     * @param string $id
     *
     * @return \Amp\Promise
     *
     * @throws \Error If the identifier given is invalid.
     */
    public function destroy(string $id): Promise {
        return $this->save($id, [], 0);
    }

    /**
     * Reloads the session contents.
     *
     * @param string $id Session ID.
     *
     * @return Promise Resolves to an instance of Session.
     */
    public function read(string $id): Promise {
        if (!$this->validate($id)) {
            // Invalid identifier given, open a new session instead.
            return $this->open();
        }

        return call(function () use ($id) {
            try {
                $result = yield $this->client->get("sess:" . $id);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't read session data", 0, $error);
            }

            if (!$result) {
                return new Session($this, $id, $this->ttl);
            }

            $firstByte = \ord($result[0]);
            $result = \substr($result, 1);

            if ($firstByte & 0x01) {
                $result = \gzinflate($result);
            }

            list($ttl, $data) = \json_decode($result, true);

            try {
                yield $this->client->expire("sess:" . $id, $ttl === -1 ? self::DEFAULT_TTL : $ttl);
            } catch (\Throwable $error) {
                throw new SessionException("couldn't set expiry", 0, $error);
            }

            return new Session($this, $id, $ttl, $data);
        });
    }

    /**
     * Unlocks the session, reloads data without saving.
     *
     * @param string $id Session ID.
     *
     * @return Promise Resolves with null on success.
     *
     * @throws \Error If the identifier given is invalid.
     */
    public function unlock(string $id): Promise {
        if (!$this->validate($id)) {
            throw new \Error("Invalid identifier");
        }

        $token = $this->locks[$id] ?? "";

        if (!$token) {
            return new Success;
        }

        return call(function () use ($id, $token) {
            try {
                yield $this->mutex->unlock($id, $token);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't unlock session", 0, $error);
            }

            unset($this->locks[$id]);
        });
    }
}
