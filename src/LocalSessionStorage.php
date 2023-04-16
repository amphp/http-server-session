<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\Cache\LocalCache;
use Amp\Serialization\CompressingSerializer;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;

/**
 * This driver saves all sessions in memory, mainly for local development purposes.
 *
 * It won't work correctly with multiple processes.
 */
final class LocalSessionStorage implements SessionStorage
{
    public const DEFAULT_SESSION_LIFETIME = 3600;

    private readonly LocalCache $storage;
    private readonly Serializer $serializer;

    public function __construct(
        ?Serializer $serializer = null,
        private readonly int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME,
    ) {
        $this->serializer = $serializer ?? new CompressingSerializer(new NativeSerializer);
        $this->storage = new LocalCache;
    }

    public function write(string $id, array $data): void
    {
        if (empty($data)) {
            try {
                $this->storage->delete($id);
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
            $this->storage->set($id, $serializedData, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't persist data for session '{$id}'", 0, $error);
        }
    }

    public function read(string $id): array
    {
        try {
            $result = $this->storage->get($id);
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
            // Cache::set() can only be used here, because we know the implementation is synchronous,
            // otherwise we'd need locking
            $this->storage->set($id, $result, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
        }

        return $data;
    }
}
