<?php

namespace Aerys\Session;

use Amp\Promise;
use Amp\Success;
use function Amp\call;

final class Session {
    const STATUS_READ = 1;
    const STATUS_LOCKED = 2;
    const STATUS_DESTROYED = 4;

    const DEFAULT_TTL = -1;

    /** @var \Aerys\Session\Driver */
    private $driver;

    /** @var string|null */
    private $id;

    /** @var int */
    private $status = 0;

    /** @var \Amp\Promise|null */
    private $pending;

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
     */
    public function isDestroyed(): bool {
        return $this->status & self::STATUS_DESTROYED;
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

            if ($this->id === null) {
                $this->id = yield $this->driver->open();
            } else {
                $this->id = yield $this->driver->regenerate($this->id);
            }

            $this->status = self::STATUS_READ | self::STATUS_LOCKED;

            return $this->id;
        });
    }

    /**
     * Reads the session data without locking the session.
     *
     * @return \Amp\Promise Resolved with the session data.
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
                $data = yield $this->driver->read($this->id);
            }

            $this->status |= self::STATUS_READ;
            return $data ?? null;
        });
    }

    /**
     * Locks the session for writing.
     *
     * @return \Amp\Promise Resolved with the session data.
     */
    public function lock(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->status & self::STATUS_DESTROYED) {
                throw new \Error("The session was destroyed");
            }

            if ($this->id === null) {
                $this->id = yield $this->driver->open();
            } else {
                $data = yield $this->driver->read($this->id);
                yield $this->driver->lock($this->id);
            }

            $this->status |= self::STATUS_READ | self::STATUS_LOCKED;
            return $data ?? null;
        });
    }

    /**
     * Saves the given data in the session. The session must be locked with either lock() or regenerate() before
     * calling this method.
     *
     * @param mixed $data Data to save in the session. Must be serializable.
     * @param int $ttl Time for the data to live in the driver storage. Note this is separate from the cookie expiry.
     *
     * @return \Amp\Promise
     */
    public function save($data, int $ttl = self::DEFAULT_TTL): Promise {
        return $this->pending = call(function () use ($data, $ttl) {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!($this->status & self::STATUS_LOCKED)) {
                throw new \Error("Cannot save an unlocked session");
            }

            yield $this->driver->save($this->id, $data, $ttl);

            $this->status &= ~self::STATUS_LOCKED;
        });
    }

    /**
     * Unlocks and destroys the session.
     *
     * @return Promise Resolving after success.
     */
    public function destroy(): Promise {
        if ($this->status & self::STATUS_DESTROYED) {
            return new Success;
        }

        $this->status = self::STATUS_DESTROYED;

        if ($this->id === null) {
            return new Success;
        }

        $id = $this->id;
        $this->id = null;

        return $this->pending = $this->driver->destroy($id);
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

            if ($this->status & self::STATUS_DESTROYED) {
                throw new \Error("The session was destroyed");
            }

            yield $this->driver->unlock($this->id);

            $this->status &= ~self::STATUS_LOCKED;
        });
    }
}
