<?php /** @noinspection PhpUndefinedClassInspection */ declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;
use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use function Amp\Sync\synchronized;

final class Session
{
    private const STATUS_READ = 1;
    private const STATUS_LOCKED = 2;

    private ?string $id;

    /** @var array<string, mixed> Session data. */
    private array $data = [];

    private int $status = 0;

    private LocalMutex $localMutex;

    private int $lockCount = 0;

    private ?Lock $lock = null;

    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly SessionStorage $storage,
        private readonly SessionIdGenerator $generator,
        ?string $clientId,
    ) {
        $this->localMutex = new LocalMutex();

        if ($clientId === null || !$generator->validate($clientId)) {
            $this->id = null;
        } else {
            $this->id = $clientId;
        }
    }

    /**
     * @return string|null Session identifier.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @return bool `true` if session data has been read.
     */
    public function isRead(): bool
    {
        return ($this->status & self::STATUS_READ) !== 0;
    }

    /**
     * @return bool `true` if the session has been locked.
     */
    public function isLocked(): bool
    {
        return ($this->status & self::STATUS_LOCKED) !== 0;
    }

    /**
     * @return bool `true` if the session is empty.
     *
     * @throws \Error If the session has not been read.
     */
    public function isEmpty(): bool
    {
        $this->ensureRead();

        return empty($this->data);
    }

    /**
     * Regenerates a session identifier.
     *
     * @return string Returns the new session identifier.
     */
    public function regenerate(): string
    {
        return synchronized($this->localMutex, function (): string {
            $this->assertLocked();

            $newId = $this->generator->generate();
            $newLock = $this->mutex->acquire($newId);

            $this->storage->write($newId, $this->data);
            \assert($this->id !== null);
            $this->storage->write($this->id, []);

            $oldLock = $this->lock;
            $oldLock?->release();

            $this->lock = $newLock;
            $this->id = $newId;

            return $this->id;
        });
    }

    /**
     * Reads the session data without locking the session.
     */
    public function read(): self
    {
        return synchronized($this->localMutex, function (): self {
            if ($this->id !== null) {
                $this->data = $this->storage->read($this->id);
            }

            $this->status |= self::STATUS_READ;

            return $this;
        });
    }

    /**
     * Locks the session for writing.
     *
     * This will implicitly reload the session data from the storage.
     */
    public function lock(): self
    {
        return synchronized($this->localMutex, function (): self {
            $id = $this->id;

            if ($id === null) {
                $newId = $this->generator->generate();
                $newLock = $this->mutex->acquire($newId);

                $this->id = $newId;
                $this->lock = $newLock;

                $this->data = [];
            } elseif (!$this->isLocked()) {
                $this->lock = $this->mutex->acquire($id);
                $this->data = $this->storage->read($id);
            }

            ++$this->lockCount;

            $this->status = self::STATUS_READ | self::STATUS_LOCKED;

            return $this;
        });
    }

    /**
     * Saves the given data in the session and unlocks it.
     *
     * The session must be locked with lock() before calling this method.
     */
    public function commit(): void
    {
        synchronized($this->localMutex, function (): void {
            $this->assertLocked();
            $this->write();
        });
    }

    /**
     * Reloads the data from the storage discarding modifications and unlocks the session.
     *
     * The session must be locked with lock() before calling this method.
     */
    public function rollback(): void
    {
        synchronized($this->localMutex, function (): void {
            $this->assertLocked();
            $this->read();
            $this->unlockInternally();
        });
    }

    /**
     * Unlocks the session.
     *
     * The session must be locked with lock() before calling this method.
     */
    public function unlock(): void
    {
        synchronized($this->localMutex, function (): void {
            $this->assertLocked();
            $this->unlockInternally();
        });
    }

    /**
     * Destroys and unlocks the session data.
     *
     * @throws \Error If the session has not been locked for writing.
     */
    public function destroy(): void
    {
        synchronized($this->localMutex, function (): void {
            $this->assertLocked();
            $this->data = [];
            $this->write();
        });
    }

    /**
     * Releases all locks on the session.
     */
    public function unlockAll(): void
    {
        synchronized($this->localMutex, function (): void {
            if (!$this->isLocked()) {
                return;
            }

            $this->lock?->release();
            $this->lock = null;
            $this->status &= ~self::STATUS_LOCKED;

            $this->lockCount = 0;
        });
    }

    public function has(string $key): bool
    {
        $this->ensureRead();

        return \array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        $this->ensureRead();

        return $this->data[$key] ?? null;
    }

    /**
     * @throws \Error If the session has not been locked for writing.
     */
    public function set(string $key, mixed $data): void
    {
        $this->assertLocked();

        $this->data[$key] = $data;
    }

    /**
     * @throws \Error If the session has not been locked for writing.
     */
    public function unset(string $key): void
    {
        $this->assertLocked();

        unset($this->data[$key]);
    }

    /**
     * @throws \Error If the session has not been read.
     */
    public function getData(): array
    {
        $this->ensureRead();

        return $this->data;
    }

    private function write(): void
    {
        if ($this->id === null) {
            throw new \Error('Invalid session');
        }

        $this->storage->write($this->id, $this->data);

        if ($this->lockCount === 1) {
            $this->lock?->release();
            $this->lock = null;
            $this->status &= ~self::STATUS_LOCKED;

            if ($this->data === []) {
                $this->id = null;
            }
        }

        --$this->lockCount;
    }

    private function ensureRead(): void
    {
        synchronized($this->localMutex, function () {
            if (!$this->isRead()) {
                $this->read();
            }
        });
    }

    private function assertLocked(): void
    {
        if (!$this->isLocked()) {
            throw new \Error('The session has not been locked');
        }
    }

    public function unlockInternally(): void
    {
        if ($this->lockCount === 1) {
            $this->lock?->release();
            $this->lock = null;
            $this->status = 0;
        }

        --$this->lockCount;
    }
}
