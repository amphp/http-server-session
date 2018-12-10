<?php

namespace Amp\Http\Server\Session;

use Amp\Promise;
use function Amp\call;

class RedisPublishDriver extends RedisDriver
{
    public function regenerate(string $oldId): Promise
    {
        return call(function () use ($oldId) {
            $newId = yield parent::regenerate($oldId);

            try {
                yield $this->getClient()->publish($this->getKeyPrefix() . 'regenerate', "{$oldId} {$newId}");
            } catch (\Throwable $error) {
                throw new SessionException("Failed to publish identifier change from session '{$oldId}' to '{$newId}'", 0, $error);
            }

            return $newId;
        });
    }

    public function save(string $id, array $data, int $ttl = null): Promise
    {
        return call(function () use ($id, $data, $ttl) {
            yield parent::save($id, $data, $ttl);

            try {
                yield $this->getClient()->publish($this->getKeyPrefix() . 'save', "{$id} " . \serialize($data));
            } catch (\Throwable $error) {
                throw new SessionException("Failed to publish update for session '${id}'", 0, $error);
            }
        });
    }
}
