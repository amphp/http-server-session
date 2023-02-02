<?php

namespace Amp\Http\Server\Session;

use Amp\Redis\QueryExecutor;
use Amp\Redis\Redis;
use Amp\Redis\RedisSetOptions;
use Amp\Serialization\CompressingSerializer;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;

final class RedisSessionStorage implements SessionStorage
{
    public const DEFAULT_SESSION_LIFETIME = 3600;

    private readonly Redis $redis;

    private readonly Serializer $serializer;

    private readonly RedisSetOptions $setOptions;

    public function __construct(
        QueryExecutor $executor,
        ?Serializer $serializer = null,
        private readonly int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME,
        private readonly string $keyPrefix = 'session:'
    ) {
        $this->redis = new Redis($executor);
        $this->serializer = $serializer ?? new CompressingSerializer(new NativeSerializer);
        $this->setOptions = (new RedisSetOptions)->withTtl($this->sessionLifetime);
    }

    public function write(string $id, array $data): void
    {
        if (empty($data)) {
            try {
                $this->redis->delete($this->keyPrefix . $id);
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
            $this->redis->set($this->keyPrefix . $id, $serializedData, $this->setOptions);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't persist data for session '{$id}'", 0, $error);
        }
    }

    public function read(string $id): array
    {
        try {
            $result = $this->redis->get($this->keyPrefix . $id);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '{$id}'", 0, $error);
        }

        if ($result === null) {
            return [];
        }

        try {
            $data = $this->serializer->unserialize($result);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '{$id}'", 0, $error);
        }

        try {
            $this->redis->expireIn($this->keyPrefix . $id, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
        }

        return $data;
    }
}
