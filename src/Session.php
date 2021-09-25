<?php /** @noinspection PhpUndefinedClassInspection */

namespace Amp\Http\Server\Session;

use Amp\Deferred;
use Amp\Promise;
use Amp\Success;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use function Amp\await;

final class Session
{
    private const STATUS_READ = 1;
    private const STATUS_LOCKED = 2;

    private KeyedMutex $mutex;

    private Storage $storage;

    private IdGenerator $generator;

    private ?string $id;

    /** @var string[] Session data. */
    private array $data = [];

    private int $status = 0;

    private Promise $pending;

    private int $openCount = 0;

    private ?Lock $lock = null;

    public function __construct(KeyedMutex $mutex, Storage $storage, IdGenerator $generator, ?string $clientId)
    {
        $this->mutex = $mutex;
        $this->storage = $storage;
        $this->generator = $generator;
        $this->pending = new Success;

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
            $this->storage->write($this->id, []);

            $oldLock = $this->lock;
            $oldLock->release();

            $this->lock = $newLock;
            $this->id = $newId;

            return $this->id;
        });
    }

    /**
     * Reads the session data without opening (locking) the session.
     *
     * @return self
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
     *
     * @return self
     */
    public function open(): self
    {
        return $this->synchronized(function (): self {
            if ($this->id === null) {
                $newId = $this->generator->generate();
                $newLock = $this->mutex->acquire($newId);

                $this->id = $newId;
                $this->lock = $newLock;

                $this->data = [];
            } elseif (!$this->isLocked()) {
                $this->lock = $this->mutex->acquire($this->id);
                $this->data = $this->storage->read($this->id);
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
                $this->lock->release();
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

            $this->lock->release();
            $this->lock = null;
            $this->status &= ~self::STATUS_LOCKED;

            $this->openCount = 0;
        });
    }

    /**
     * @param string $key
     *
     * @return bool
     *
     * @throws \Error If the session has not been read.
     */
    public function has(string $key): bool
    {
        $this->assertRead();

        return \array_key_exists($key, $this->data);
    }

    /**
     * @param string $key
     *
     * @return mixed
     *
     * @throws \Error If the session has not been read.
     */
    public function get(string $key)
    {
        $this->assertRead();

        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed  $data
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function set(string $key, $data): void
    {
        $this->assertLocked();

        $this->data[$key] = $data;
    }

    /**
     * @param string $key
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function unset(string $key): void
    {
        $this->assertLocked();

        unset($this->data[$key]);
    }

    /**
     * @return mixed[]
     *
     * @throws \Error If the session has not been read.
     */
    public function getData(): array
    {
        $this->assertRead();

        return $this->data;
    }

    private function unsynchronizedSave() {
        $this->storage->write($this->id, $this->data);

        if ($this->openCount === 1) {
            $this->lock->release();
            $this->lock = null;
            $this->status &= ~self::STATUS_LOCKED;

            if ($this->data === []) {
                $this->id = null;
            }
        }

        --$this->openCount;
    }

    private function synchronized(callable $callable): mixed
    {
        await($this->pending);

        $deferred = new Deferred;
        $this->pending = $deferred->promise();

        try {
            return $callable();
        } finally {
            $deferred->resolve();
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
