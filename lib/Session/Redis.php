<?php

namespace Aerys\Session;

use Amp\Deferred;
use Amp\Promise;
use Amp\Redis\Client;
use Amp\Redis\Mutex;
use Amp\Success;
use function Amp\cancel;
use function Amp\pipe;
use function Amp\repeat;

class Redis implements Driver {
    const COMPRESSION_THRESHOLD = 256;

    private $client;
    private $mutex;
    private $locks;
    private $repeatTimer;

    public function __construct(Client $client, Mutex $mutex) {
        $this->client = $client;
        $this->mutex = $mutex;
        $this->locks = [];

        $this->repeatTimer = repeat(function () {
            foreach ($this->locks as $id => $token) {
                $this->mutex->renew($id, $token);
            }
        }, $this->mutex->getTTL() / 2);
    }

    public function __destruct() {
        cancel($this->repeatTimer);
    }

    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function open(string $id): Promise {
        $token = uniqid("", true);
        $promisor = new Deferred;

        $this->mutex->lock($id, $token)->when(function ($error) use ($id, $token, $promisor) {
            if ($error) {
                $promisor->fail(new Exception("couldn't acquire a lock", 0, $error));
            } else {
                $this->locks[$id] = $token;
                $promisor->succeed($this->read($id));
            }
        });

        return $promisor->promise();
    }

    /**
     * Saves and unlocks a session
     * @param array $data to store (an empty array is equivalent to destruction of the session)
     * @param int $ttl time until session expiration (always > 0)
     * @return \Amp\Promise resolving after success
     */
    public function save(string $id, array $data, int $ttl): Promise {
        $promisor = new Deferred;

        if (empty($data)) {
            $this->client->del("sess:" . $id)->when(function ($error) use ($id, $promisor) {
                if ($error) {
                    $promisor->fail(new Exception("couldn't delete session", 0, $error));
                } else {
                    $promisor->succeed($this->unlock($id));
                }
            });
        } else {
            $data = json_encode([$ttl, $data]);
            $flags = 0;

            if (strlen($data) > self::COMPRESSION_THRESHOLD) {
                $data = gzdeflate($data, 1);
                $flags |= 0x01;
            }

            $data = $flags % 256 . $data;

            $this->client->set("sess:" . $id, $data, $ttl)->when(function ($error) use ($id, $promisor) {
                if ($error) {
                    $promisor->fail(new Exception("couldn't persist session data", 0, $error));
                } else {
                    $promisor->succeed($this->unlock($id));
                }
            });
        }

        return $promisor->promise();
    }

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(string $oldId, string $newId): Promise {
        $token = uniqid("", true);
        $promisor = new Deferred;

        $this->mutex->lock($newId, $token)->when(function ($error) use ($oldId, $newId, $token, $promisor) {
            if ($error) {
                $promisor->fail(new Exception("couldn't acquire lock for new session id", 0, $error));
            } else {
                $this->locks[$newId] = $token;
                $promisor->succeed($this->unlock($oldId));
            }
        });

        return $promisor->promise();
    }

    /**
     * Reloads the session contents
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function read(string $id): Promise {
        $promisor = new Deferred;

        $this->client->get("sess:" . $id)->when(function ($error, $result) use ($id, $promisor) {
            if ($error) {
                $promisor->fail(new Exception("couldn't read session data", 0, $error));
            } else if ($result) {
                $firstByte = $result[0];
                $result = substr($result, 1);

                if ($firstByte & 0x01) {
                    $result = gzinflate($result);
                }

                list($ttl, $data) = json_decode($result);

                $this->client->expire("sess:" . $id, $ttl)->when(function ($error) use ($data, $promisor) {
                    if ($error) {
                        $promisor->fail(new Exception("couldn't set expiry", 0, $error));
                    } else {
                        $promisor->succeed($data);
                    }
                });
            } else {
                $promisor->succeed([]);
            }
        });

        return $promisor->promise();
    }

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function unlock(string $id): Promise {
        $token = $this->locks[$id] ?? "";

        if (!$token) {
            return new Success;
        }

        $promisor = new Deferred;

        $this->mutex->unlock($id, $token)->when(function ($error) use ($id, $promisor) {
            if ($error) {
                $promisor->fail(new Exception("couldn't unlock session", 0, $error));
            } else {
                unset($this->locks[$id]);
                $promisor->succeed();
            }
        });

        return $promisor->promise();
    }
}