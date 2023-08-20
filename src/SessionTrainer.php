<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace Amp\Http\Server\Session;

use Amp\Http\Server\Session\Internal\TestSessionIdGenerator;
use Amp\Http\Server\Session\Internal\TestSessionStorage;

/**
 * Test implementation specifically for writing unit tests.
 *
 * Do not use this implementation in production!
 */
final class SessionTrainer
{
    private readonly LocalSessionStorage $backingStorage;
    private readonly TestSessionIdGenerator $idGenerator;
    private readonly TestSessionStorage $storage;
    private readonly SessionFactory $factory;

    public function __construct()
    {
        $this->backingStorage = new LocalSessionStorage();
        $this->idGenerator = new TestSessionIdGenerator();
        $this->storage = new TestSessionStorage($this->backingStorage);
        $this->factory = new SessionFactory($this->storage, $this->storage, $this->idGenerator);
    }

    public function givenSession(string $id, array $data): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->backingStorage->write($id, $data);
    }

    public function getAddedKeys(string $id): array
    {
        $firstReadKeys = $this->storage->getFirstReadKeys($id);
        if ($firstReadKeys === null) {
            return []; // no read, no modification
        }

        $currentKeys = \array_keys($this->backingStorage->read($id));

        return \array_filter($currentKeys, fn (string $key) => !\in_array($key, $firstReadKeys, true));
    }

    public function getRemovedKeys(string $id): array
    {
        $firstReadKeys = $this->storage->getFirstReadKeys($id);
        if ($firstReadKeys === null) {
            return []; // no read, no modification
        }

        $currentKeys = \array_keys($this->backingStorage->read($id));

        return \array_filter($firstReadKeys, fn (string $key) => !\in_array($key, $currentKeys, true));
    }

    public function getReadCount(string $id): int
    {
        return $this->storage->getReadCount($id);
    }

    public function getWriteCount(string $id): int
    {
        return $this->storage->getWriteCount($id);
    }

    public function getLockCount(string $id): int
    {
        return $this->storage->getLockCount($id);
    }

    public function isLocked(string $id): bool
    {
        return $this->storage->isLocked($id);
    }

    public function getFactory(): SessionFactory
    {
        return $this->factory;
    }
}
