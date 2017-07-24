<?php

namespace Aerys\Session;

use Amp\Loop;
use Amp\Promise;
use Amp\Redis\Client;
use Amp\Success;
use Kelunik\RedisMutex\Mutex;
use function Amp\call;

class Redis implements Driver {
    const COMPRESSION_THRESHOLD = 256;

    protected $client;
    protected $mutex;
    protected $locks;
    protected $repeatTimer;

    public function __construct(Client $client, Mutex $mutex) {
        $this->client = $client;
        $this->mutex = $mutex;
        $this->locks = [];

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
     * Creates a lock and reads the current session data.
     *
     * @param string $id Session ID.
     *
     * @return Promise Resolving to an array with current session data.
     */
    public function open(string $id): Promise {
        return call(function () use ($id) {
            $token = bin2hex(random_bytes(16));

            try {
                yield $this->mutex->lock($id, $token);
            } catch (\Throwable $error) {
                throw new Exception("Couldn't acquire a lock", 0, $error);
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
     * @return Promise resolving after success
     */
    public function save(string $id, array $data, int $ttl): Promise {
        return call(function () use ($id, $data, $ttl) {
            if (empty($data)) {
                try {
                    yield $this->client->del("sess:" . $id);
                } catch (\Throwable $error) {
                    throw new Exception("Couldn't delete session", 0, $error);
                }

                return $this->unlock($id);
            } else {
                $data = json_encode([$ttl, $data]);
                $flags = 0;

                if (strlen($data) > self::COMPRESSION_THRESHOLD) {
                    $data = gzdeflate($data, 1);
                    $flags |= 0x01;
                }

                $data = $flags % 256 . $data;

                try {
                    yield $this->client->set("sess:" . $id, $data, $ttl);
                } catch (\Throwable $error) {
                    throw new Exception("couldn't persist session data", 0, $error);
                }

                return $this->unlock($id);
            }
        });
    }

    /**
     * Regenerates a session ID.
     *
     * @param string $oldId Old session ID.
     * @param string $newId New session ID.
     *
     * @return Promise Resolving after success.
     */
    public function regenerate(string $oldId, string $newId): Promise {
        return call(function () use ($oldId, $newId) {
            $token = \bin2hex(\random_bytes(16));

            try {
                yield $this->mutex->lock($newId, $token);
            } catch (\Throwable $error) {
                throw new Exception("Couldn't acquire lock for new session ID", 0, $error);
            }

            $this->locks[$newId] = $token;

            return $this->save($oldId, [], 0);
        });
    }

    /**
     * Reloads the session contents.
     *
     * @param string $id Session ID.
     *
     * @return Promise resolving to an array with current session data.
     */
    public function read(string $id): Promise {
        return call(function () use ($id) {
            try {
                $result = yield $this->client->get("sess:" . $id);
            } catch (\Throwable $error) {
                throw new Exception("Couldn't read session data", 0, $error);
            }

            if (!$result) {
                return [];
            }

            $firstByte = $result[0];
            $result = substr($result, 1);

            if ($firstByte & 0x01) {
                $result = gzinflate($result);
            }

            list($ttl, $data) = json_decode($result, true);

            try {
                yield $this->client->expire("sess:" . $id, $ttl);
            } catch (\Throwable $error) {
                throw new Exception("couldn't set expiry", 0, $error);
            }

            return $data;
        });
    }

    /**
     * Unlocks the session, reloads data without saving.
     *
     * @param string $id Session ID.
     *
     * @return Promise resolving to an array with current session data.
     */
    public function unlock(string $id): Promise {
        $token = $this->locks[$id] ?? "";

        if (!$token) {
            return new Success;
        }

        return call(function () use ($id, $token) {
            try {
                yield $this->mutex->unlock($id, $token);
            } catch (\Throwable $error) {
                throw new Exception("Couldn't unlock session", 0, $error);
            }

            unset($this->locks[$id]);
        });
    }
}
