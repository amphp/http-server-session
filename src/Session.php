<?php

namespace Amp\Http\Server\Session;

use Amp\Promise;
use function Amp\call;

final class Session
{
    private const STATUS_READ = 1;
    private const STATUS_LOCKED = 2;

    /** @var Driver */
    private $driver;

    /** @var string|null */
    private $id;

    /** @var string[] Session data. */
    private $data = [];

    /** @var int */
    private $status = 0;

    /** @var Promise|null */
    private $pending;

    /** @var int */
    private $openCount = 0;

    public function __construct(Driver $driver, ?string $id)
    {
        $this->driver = $driver;
        $this->id = $id;

        if ($this->id !== null && !$this->driver->validate($id)) {
            $this->id = null;
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
        return $this->status & self::STATUS_READ !== 0;
    }

    /**
     * @return bool `true` if the session has been locked.
     */
    public function isLocked(): bool
    {
        return $this->status & self::STATUS_LOCKED !== 0;
    }

    /**
     * @return bool `true` if the session is empty.
     *
     * @throws \Error If the session has not been read.
     */
    public function isEmpty(): bool
    {
        if ($this->status & self::STATUS_READ === 0) {
            throw new \Error('The session has not been read');
        }

        return empty($this->data);
    }

    /**
     * Regenerates a session identifier and locks the session.
     *
     * @return Promise Resolving with the new session identifier.
     */
    public function regenerate(): Promise
    {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->id === null || !$this->isLocked()) {
                throw new \Error('Cannot save an unlocked session');
            }

            $newId = yield $this->driver->create();

            yield $this->driver->save($newId, $this->data);
            yield $this->driver->save($this->id, []);

            $this->id = $newId;
            $this->status = self::STATUS_READ | self::STATUS_LOCKED;

            return $this->id;
        });
    }

    /**
     * Reads the session data without opening (locking) the session.
     *
     * @return Promise Resolved with the session.
     */
    public function read(): Promise
    {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->id !== null) {
                $this->data = yield $this->driver->read($this->id);
            }

            $this->status |= self::STATUS_READ;

            return $this;
        });
    }

    /**
     * Opens the session for writing.
     *
     * @return Promise Resolved with the session.
     */
    public function open(): Promise
    {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->id === null) {
                $this->id = yield $this->driver->create();
            } else {
                $this->data = yield $this->driver->lock($this->id);
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
     *
     * @return Promise
     */
    public function save(): Promise
    {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!$this->isLocked()) {
                throw new \Error('Cannot save an unlocked session');
            }

            if ($this->data === []) {
                yield $this->driver->save($this->id, []);
            } else {
                yield $this->driver->save($this->id, $this->data);
            }

            if ($this->openCount === 1) {
                yield $this->driver->unlock($this->id);
                $this->status &= ~self::STATUS_LOCKED;

                if ($this->data === []) {
                    $this->id = null;
                }
            }

            --$this->openCount;
        });
    }

    /**
     * Destroys and unlocks the session data.
     *
     * @return Promise Resolving after success.
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function destroy(): Promise
    {
        $this->assertLocked();

        $this->data = [];

        return $this->save();
    }

    /**
     * Unlocks the session.
     *
     * @return Promise
     */
    public function unlock(): Promise
    {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!$this->isLocked()) {
                return;
            }

            if ($this->openCount === 1) {
                yield $this->driver->unlock($this->id);
                $this->status &= ~self::STATUS_LOCKED;
            }

            --$this->openCount;
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
    public function set(string $key, $data)
    {
        $this->assertLocked();

        $this->data[$key] = $data;
    }

    /**
     * @param string $key
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function unset(string $key)
    {
        $this->assertLocked();

        unset($this->data[$key]);
    }

    /**
     * @return string[]
     *
     * @throws \Error If the session has not been read.
     */
    public function getData(): array
    {
        $this->assertRead();

        return $this->data;
    }

    private function assertRead(): void
    {
        if (!$this->isRead()) {
            throw new \Error('The session has not been read');
        }
    }

    private function assertLocked(): void
    {
        if (!($this->status & self::STATUS_LOCKED)) {
            throw new \Error('The session has not been locked');
        }
    }
}
