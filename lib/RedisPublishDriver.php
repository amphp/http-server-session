<?php

namespace Aerys\Session;

use Amp\Promise;
use function Amp\call;

class RedisPublishDriver extends RedisDriver {
    public function regenerate(string $oldId): Promise {
        return call(function () use ($oldId) {
            try {
                $newId = yield parent::regenerate($oldId);
                yield $this->getClient()->publish("sess:regenerate", "{$oldId} {$newId}");
            } catch (\Throwable $error) {
                throw new SessionException("Failed to publish regeneration", 0, $error);
            }
        });
    }

    public function save(string $id, array $data, int $ttl): Promise {
        return call(function () use ($id, $data, $ttl) {
            try {
                yield parent::save($id, $data, $ttl);

                $data = \json_encode($data);
                yield $this->getClient()->publish("sess:update", "{$id} {$data}");
            } catch (\Throwable $error) {
                throw new SessionException("Failed to publish update", 0, $error);
            }
        });
    }
}
