<?php

namespace Aerys\Session;

use Amp\Promise;
use Amp\Reactor;
use Amp\Redis\Client;
use Amp\Redis\Mutex;
use Amp\Success;
use function Amp\pipe;

class Redis implements Driver {
    private $reactor;
    private $client;
    private $mutex;
    private $locks;

    public function __construct(Client $client, Mutex $mutex, Reactor $reactor) {
        $this->client = $client;
        $this->mutex = $mutex;
        $this->reactor = $reactor;
        $this->locks = [];

        $this->reactor->repeat(function () {
            foreach ($this->locks as $id => $token) {
                $this->mutex->renew($id, $token);
            }
        }, $this->mutex->getTTL() / 2);
    }

    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function open(string $id): Promise {
        $token = uniqid("", true);

        return pipe($this->mutex->lock($id, $token), function () use ($id, $token) {
            $this->locks[$id] = $token;
            return $this->read($id);
        });
    }

    /**
     * Saves and unlocks a session
     * @param array $data to store (an empty array is equivalent to destruction of the session)
     * @param int $ttl time until session expiration (always > 0)
     * @return \Amp\Promise resolving after success
     */
    public function save(string $id, array $data, int $ttl): Promise {
        if (empty($data)) {
            return pipe($this->client->del("sess:" . $id), function () use ($id) {
                return $this->unlock($id);
            });
        } else {
            return pipe($this->client->set("sess:" . $id, serialize($data), $ttl), function () use ($id) {
                return $this->unlock($id);
            });
        }
    }

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(string $oldId, string $newId): Promise {
        $token = $this->locks[$oldId] ?? "";

        return pipe($this->mutex->lock($newId, $token), function () use ($oldId, $token) {
            return $this->mutex->unlock($oldId, $token);
        });
    }

    /**
     * Reloads the session contents
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function read(string $id): Promise {
        return pipe($this->client->get("sess:" . $id), function ($result) {
            if ($result) {
                return unserialize($result);
            } else {
                return [];
            }
        });
    }

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function unlock(string $id): Promise {
        $token = $this->locks[$id] ?? "";

        if ($token) {
            return pipe($this->mutex->unlock($id, $token), function () use ($id) {
                unset($this->locks[$id]);
            });
        }

        return new Success;
    }
}