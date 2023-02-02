<?php /** @noinspection PhpUndefinedClassInspection */

namespace Amp\Http\Server\Session;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;

final class Session
{
    private const STATUS_READ = 1;
    private const STATUS_LOCKED = 2;

    private ?string $id;

    /** @var array<string, string> Session data. */
    private array $data = [];

    private int $status = 0;

    private Future $pending;

    private int $openCount = 0;

    private ?Lock $lock = null;

    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly SessionStorage $storage,
        private readonly SessionIdGenerator $generator,
        ?string $clientId,
    ) {
        $this->pending = Future::complete();

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
        $this->assertRead();

        return empty($this->data);
    }

    /**
     * Regenerates a session identifier.
     *
     * @return string Returns the new session identifier.
     */
    public function regenerate(): string
    {
        return $this->synchronized(function (): string {
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
     * Reads the session data without opening (locking) the session.
     */
    public function read(): self
    {
        return $this->synchronized(function (): self {
            if ($this->id !== null) {
                $this->data = $this->storage->read($this->id);
            }

            $this->status |= self::STATUS_READ;

            return $this;
        });
    }

    /**
     * Opens the session for writing.
     */
    public function open(): self
    {
        return $this->synchronized(function (): self {
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

            ++$this->openCount;

            $this->status = self::STATUS_READ | self::STATUS_LOCKED;

            return $this;
        });
    }

    /**
     * Saves the given data in the session.
     *
     * The session must be locked with either open() before calling this method.
     */
    public function save(): void
    {
        $this->synchronized(function (): void {
            $this->assertLocked();
            $this->unsynchronizedSave();
        });
    }

    /**
     * Destroys and unlocks the session data.
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function destroy(): void
    {
        $this->synchronized(function (): void {
            $this->assertLocked();

            $this->data = [];

            $this->unsynchronizedSave();
        });
    }

    /**
     * Unlocks the session.
     */
    public function unlock(): void
    {
        $this->synchronized(function (): void {
            if (!$this->isLocked()) {
                return;
            }

            if ($this->openCount === 1) {
                $this->lock?->release();
                $this->lock = null;
                $this->status &= ~self::STATUS_LOCKED;
            }

            --$this->openCount;
        });
    }

    /**
     * Releases all locks on the session.
     */
    public function unlockAll(): void
    {
        $this->synchronized(function (): void {
            if (!$this->isLocked()) {
                return;
            }

            $this->lock?->release();
            $this->lock = null;
            $this->status &= ~self::STATUS_LOCKED;

            $this->openCount = 0;
        });
    }

    /**
     * @throws \Error If the session has not been read.
     */
    public function has(string $key): bool
    {
        $this->assertRead();

        return \array_key_exists($key, $this->data);
    }

    /**
     * @throws \Error If the session has not been read.
     */
    public function get(string $key): ?string
    {
        $this->assertRead();

        return $this->data[$key] ?? null;
    }

    /**
     * @throws \Error If the session has not been opened for writing.
     */
    public function set(string $key, mixed $data): void
    {
        $this->assertLocked();

        $this->data[$key] = $data;
    }

    /**
     * @throws \Error If the session has not been opened for writing.
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
        $this->assertRead();

        return $this->data;
    }

    private function unsynchronizedSave(): void
    {
        if ($this->id === null) {
            throw new \Error('Invalid session');
        }

        $this->storage->write($this->id, $this->data);

        if ($this->openCount === 1) {
            $this->lock?->release();
            $this->lock = null;
            $this->status &= ~self::STATUS_LOCKED;

            if ($this->data === []) {
                $this->id = null;
            }
        }

        --$this->openCount;
    }

    private function synchronized(\Closure $closure): mixed
    {
        $this->pending->await();

        $deferred = new DeferredFuture();
        $this->pending = $deferred->getFuture();

        try {
            return $closure();
        } finally {
            $deferred->complete();
        }
    }

    private function assertRead(): void
    {
        if (!$this->isRead()) {
            throw new \Error('The session has not been read');
        }
    }

    private function assertLocked(): void
    {
        if (!$this->isLocked()) {
            throw new \Error('The session has not been locked');
        }
    }
}
