<?php

namespace Amp\Http\Server\Session;

use Amp\Redis\QueryExecutorFactory;
use Amp\Redis\Redis;
use Amp\Redis\SetOptions;
use Amp\Serialization\CompressingSerializer;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;

final class RedisStorage implements Storage
{
    public const DEFAULT_SESSION_LIFETIME = 3600;

    private Redis $redis;

    private string $keyPrefix;

    private int $sessionLifetime;

    private Serializer $serializer;

    /**
     * @param QueryExecutorFactory $executorFactory
     * @param Serializer|null      $serializer
     * @param int                  $sessionLifetime
     * @param string               $keyPrefix
     */
    public function __construct(
        QueryExecutorFactory $executorFactory,
        ?Serializer $serializer = null,
        int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME,
        string $keyPrefix = 'session:'
    ) {
        $this->redis = new Redis($executorFactory->createQueryExecutor());
        $this->serializer = $serializer ?? new CompressingSerializer(new NativeSerializer);
        $this->sessionLifetime = $sessionLifetime;
        $this->keyPrefix = $keyPrefix;
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
            $options = (new SetOptions)->withTtl($this->sessionLifetime);
            $this->redis->set($this->keyPrefix . $id, $serializedData, $options);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't persist data for session '{$id}'", 0, $error);
        }
    }

    public function read(string $id): array
    {
        try {
            $result = $this->redis->get($this->keyPrefix . $id);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '${id}'", 0, $error);
        }

        if ($result === null) {
            return [];
        }

        try {
            $data = $this->serializer->unserialize($result);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '${id}'", 0, $error);
        }

        try {
            $this->redis->expireIn($this->keyPrefix . $id, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
        }

        return $data;
    }
}
