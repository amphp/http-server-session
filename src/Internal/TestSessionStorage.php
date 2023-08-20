<?php declare(strict_types=1);

namespace Amp\Http\Server\Session\Internal;

use Amp\Http\Server\Session\SessionStorage;
use Amp\Sync\KeyedMutex;
use Amp\Sync\LocalKeyedMutex;
use Amp\Sync\Lock;

/** @internal */
final class TestSessionStorage implements KeyedMutex, SessionStorage
{
    private readonly LocalKeyedMutex $keyedMutex;
    private readonly SessionStorage $storage;

    private array $locked = [];
    private array $readCounts = [];
    private array $writeCounts = [];
    private array $lockCounts = [];
    private array $firstReadKeys = [];

    public function __construct(SessionStorage $storage)
    {
        $this->keyedMutex = new LocalKeyedMutex();
        $this->storage = $storage;
    }

    public function isLocked(string $id): bool
    {
        return isset($this->locked[$id]);
    }

    public function getLockCount(string $id): int
    {
        return $this->lockCounts[$id] ?? 0;
    }

    public function getReadCount(string $id): int
    {
        return $this->readCounts[$id] ?? 0;
    }

    public function getWriteCount(string $id): int
    {
        return $this->writeCounts[$id] ?? 0;
    }

    public function getFirstReadKeys(string $id): ?array
    {
        return $this->firstReadKeys[$id] ?? null;
    }

    public function acquire(string $key): Lock
    {
        $this->lockCounts[$key] ??= 0;
        $this->lockCounts[$key]++;

        $lock = $this->keyedMutex->acquire($key);
        $this->locked[$key] = true;

        return new Lock(function () use ($lock, $key) {
            $lock->release();
            unset($this->locked[$key]);
        });
    }

    public function read(string $id): array
    {
        $this->readCounts[$id] ??= 0;
        $this->readCounts[$id]++;

        $data = $this->storage->read($id);
        $this->firstReadKeys[$id] ??= \array_keys($data);

        return $data;
    }

    public function write(string $id, array $data): void
    {
        $this->writeCounts[$id] ??= 0;
        $this->writeCounts[$id]++;

        $this->storage->write($id, $data);
    }
}
