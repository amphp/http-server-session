<?php

namespace Amp\Http\Server\Session;

use Amp\Promise;
use function Amp\call;

final class Session {
    const STATUS_READ = 1;
    const STATUS_LOCKED = 2;
    const STATUS_DESTROYED = 4;

    const DEFAULT_TTL = -1;

    /** @var \Amp\Http\Server\Session\Driver */
    private $driver;

    /** @var string|null */
    private $id;

    /** @var string[] Session data. */
    private $data = [];

    /** @var int */
    private $status = 0;

    /** @var \Amp\Promise|null */
    private $pending;

    /** @var int */
    private $openCount = 0;

    public function __construct(Driver $driver, string $id = null) {
        $this->driver = $driver;
        $this->id = $id;

        if ($this->id !== null && !$this->driver->validate($id)) {
            $this->id = null;
        }
    }

    /**
     * @return string|null Session identifier.
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return bool `true` if session data has been read.
     */
    public function isRead(): bool {
        return $this->status & self::STATUS_READ;
    }

    /**
     * @return bool `true` if the session has been locked.
     */
    public function isLocked(): bool {
        return $this->status & self::STATUS_LOCKED;
    }

    /**
     * @return bool `true` if the session has been destroyed.
     *
     * @throws \Error If the session has not been read.
     */
    public function isEmpty(): bool {
        if (!($this->status & self::STATUS_READ)) {
            throw new \Error("The session has not been read");
        }

        return empty($this->data);
    }

    /**
     * Regenerates a session identifier and locks the session.
     *
     * @return Promise Resolving with the new session identifier.
     */
    public function regenerate(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->id === null || !($this->status & self::STATUS_LOCKED)) {
                throw new \Error("Cannot save an unlocked session");
            }

            $this->id = yield $this->driver->regenerate($this->id);

            $this->status = self::STATUS_READ | self::STATUS_LOCKED;

            return $this->id;
        });
    }

    /**
     * Reads the session data without opening (locking) the session.
     *
     * @return \Amp\Promise Resolved with the session.
     */
    public function read(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->status & self::STATUS_DESTROYED) {
                throw new \Error("The session was destroyed");
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
     * @return \Amp\Promise Resolved with the session.
     */
    public function open(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->id === null) {
                $this->id = yield $this->driver->open();
            } else {
                $this->data = yield $this->driver->lock($this->id);
            }

            ++$this->openCount;

            $this->status |= self::STATUS_READ | self::STATUS_LOCKED;

            return $this;
        });
    }

    /**
     * Saves the given data in the session. The session must be locked with either lock() or regenerate() before
     * calling this method.
     *
     * @param int $ttl Time for the data to live in the driver storage. Note this is separate from the cookie expiry.
     *
     * @return \Amp\Promise
     */
    public function save(int $ttl = self::DEFAULT_TTL): Promise {
        return $this->pending = call(function () use ($ttl) {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!($this->status & self::STATUS_LOCKED)) {
                throw new \Error("Cannot save an unlocked session");
            }

            if ($this->data === []) {
                yield $this->driver->save($this->id, [], 0);
            } else {
                yield $this->driver->save($this->id, $this->data, $ttl);
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
     * Unlocks and destroys the session.
     *
     * @return Promise Resolving after success.
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function destroy(): Promise {
        if (!($this->status & self::STATUS_LOCKED)) {
            throw new \Error("Cannot destroy an unlocked session");
        }

        $this->data = [];

        return $this->pending = $this->save(0);
    }

    /**
     * Unlocks the session.
     *
     * @return \Amp\Promise
     */
    public function unlock(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!($this->status & self::STATUS_LOCKED)) {
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
    public function has(string $key): bool {
        if (!($this->status & self::STATUS_READ)) {
            throw new \Error("The session has not been read");
        }

        return \array_key_exists($key, $this->data);
    }

    /**
     * @param string $key
     *
     * @return mixed
     *
     * @throws \Error If the session has not been read.
     */
    public function get(string $key) {
        if (!($this->status & self::STATUS_READ)) {
            throw new \Error("The session has not been read");
        }

        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed $data
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function set(string $key, $data) {
        if (!($this->status & self::STATUS_LOCKED)) {
            throw new \Error("The session has not been opened for writing");
        }

        $this->data[$key] = $data;
    }

    /**
     * @param string $key
     *
     * @throws \Error If the session has not been opened for writing.
     */
    public function unset(string $key) {
        if (!($this->status & self::STATUS_LOCKED)) {
            throw new \Error("The session has not been opened for writing");
        }

        unset($this->data[$key]);
    }

    /**
     * @return string[]
     *
     * @throws \Error If the session has not been read.
     */
    public function getData(): array {
        if (!($this->status & self::STATUS_READ)) {
            throw new \Error("The session has not been read");
        }

        return $this->data;
    }
}
