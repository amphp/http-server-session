<?php

namespace Aerys\Session;

use Amp\Deferred;
use Amp\Promise;

class RedisPublish extends Redis {
    public function regenerate(string $oldId, string $newId): Promise {
        $promisor = new Deferred;

        parent::regenerate($oldId, $newId)->when(function ($error, $result) use ($oldId, $newId, $promisor) {
            if ($error) {
                $promisor->fail($error);
            } else {
                $this->client->publish("sess:regenerate", "{$oldId} {$newId}")->when(function ($error) use ($result, $promisor) {
                    if ($error) {
                        $promisor->fail(new Exception("failed to publish regeneration", 0, $error));
                    } else {
                        $promisor->succeed($result);
                    }
                });
            }
        });

        return $promisor->promise();
    }

    public function save(string $id, array $data, int $ttl): Promise {
        $promisor = new Deferred;

        parent::save($id, $data, $ttl)->when(function ($error, $result) use ($id, $data, $promisor) {
            if ($error) {
                $promisor->fail($error);
            } else {
                $data = json_encode($data);

                $this->client->publish("sess:update", "{$id} {$data}")->when(function ($error) use ($result, $promisor) {
                    if ($error) {
                        $promisor->fail(new Exception("failed to publish update", 0, $error));
                    } else {
                        $promisor->succeed($result);
                    }
                });
            }
        });

        return $promisor->promise();
    }
}