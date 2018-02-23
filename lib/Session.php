<?php

namespace Aerys\Session;

use function Amp\call;
use Amp\Promise;
use Amp\Success;

class Session {
    const STATUS_READ = 1;
    const STATUS_LOCKED = 2;
    const STATUS_DESTROYED = 4;

    const DEFAULT_TTL = -1;

    /** @var \Aerys\Session\Driver */
    private $driver;

    /** @var string|null */
    private $id;

    /** @var string[] */
    private $data = [];

    /** @var int */
    private $status = 0;

    /** @var \Amp\Promise|null */
    private $pending;

    public function __construct(Driver $driver, string $id = null) {
        $this->driver = $driver;
        $this->id = $id;
    }

    /**
     * @return string|null Session identifier.
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return bool `true` if the session has been unlocked.
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
     * Regenerates a session identifier.
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

    public function read(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if ($this->status & self::STATUS_DESTROYED) {
                throw new \Error("The session was destroyed");
            }

            if ($this->status & self::STATUS_READ) {
                return $this->data;
            }

            if ($this->id === null) {
                $this->id = yield $this->driver->open();
            } else {
                $this->data = yield $this->driver->read($this->id);
            }

            $this->status |= self::STATUS_READ;
            return $this->data;
        });
    }

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
                if (!($this->status & self::STATUS_READ)) {
                    $this->data = yield $this->driver->read($this->id);
                }

                yield $this->driver->lock($this->id);
            }

            $this->status |= self::STATUS_READ | self::STATUS_LOCKED;
            return $this->data;
        });
    }

    public function save(array $data, int $ttl = self::DEFAULT_TTL): Promise {
        return $this->pending = call(function () use ($data, $ttl) {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!($this->status & self::STATUS_LOCKED)) {
                throw new \Error("Cannot save an unlocked session");
            }

            yield $this->driver->save($this->id, $data, $ttl);

            $this->status &= ~self::STATUS_LOCKED;
            $this->data = $data;
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
     * Unlocks the session and discards any changes made to the session instance.
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
            return $this->data;
        });

    }
}
