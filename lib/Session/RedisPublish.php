<?php

namespace Aerys\Session;

use Amp\Promise;
use function Amp\call;

class RedisPublish extends Redis {
    public function regenerate(string $oldId, string $newId): Promise {
        return call(function () use ($oldId, $newId) {
            try {
                yield parent::regenerate($oldId, $newId);
                yield $this->client->publish("sess:regenerate", "{$oldId} {$newId}");
            } catch (\Throwable $error) {
                throw new Exception("Failed to publish regeneration", 0, $error);
            }
        });
    }

    public function save(string $id, array $data, int $ttl): Promise {
        return call(function () use ($id, $data, $ttl) {
            try {
                yield parent::save($id, $data, $ttl);

                $data = \json_encode($data);
                yield $this->client->publish("sess:update", "{$id} {$data}");
            } catch (\Throwable $error) {
                throw new Exception("Failed to publish update", 0, $error);
            }
        });
    }
}
