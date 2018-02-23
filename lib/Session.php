<?php

namespace Aerys\Session;

use function Amp\call;
use Amp\Promise;

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
    private $ttl = self::DEFAULT_TTL;

    /** @var int */
    private $status = 0;

    /** @var \Amp\Promise|null */
    private $pending;

    public function __construct(Driver $driver, string $id = null, int $ttl = self::DEFAULT_TTL) {
        $this->driver = $driver;
        $this->id = $id;
        $this->ttl = $ttl;
    }

    /**
     * @return string Session identifier.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @return int
     */
    public function getTtl(): int {
        return $this->ttl;
    }

    /**
     * @param int $ttl
     */
    public function setTtl(int $ttl) {
        $this->ttl = $ttl;
    }

    public function isOpen(): bool {
        return $this->status & self::STATUS_READ;
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

            $this->status |= self::STATUS_LOCKED;

            return $this->id;
        });
    }

    public function read(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
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

            if ($this->status & self::STATUS_LOCKED) {
                return $this->data;
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

    public function save(): Promise {
        return $this->pending = call(function () {
            if ($this->pending) {
                yield $this->pending;
            }

            if (!($this->status & self::STATUS_LOCKED)) {
                throw new \Error("Cannot save an unlocked session");
            }

            yield $this->driver->save($this->id, $this->data, $this->ttl);

            $this->status &= ~self::STATUS_LOCKED;
        });
    }

    /**
     * Unlocks and destroys the session.
     *
     * @return Promise Resolving after success.
     */
    public function destroy(): Promise {
        $this->status = self::STATUS_DESTROYED;
        return $this->pending = $this->driver->destroy($this->id);
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

            if (!($this->status & self::STATUS_LOCKED)) {
                return;
            }

            yield $this->driver->unlock($this->id);

            $this->status &= ~self::STATUS_LOCKED;

            $this->data = $this->driver->read($this->id);
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
            throw new \Error("Session must be read with read() or locked with lock() before calling get()");
        }

        return \array_key_exists($key, $this->data);
    }

    /**
     * @param string $key
     *
     * @return string|null
     *
     * @throws \Error If the session has not been read.
     */
    public function get(string $key) {
        if (!($this->status & self::STATUS_READ)) {
            throw new \Error("Session must be read with read() or locked with lock() before calling get()");
        }

        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @throws \Error If the session has not been locked or has been unlocked.
     */
    public function set(string $key, string $value) {
        if (!($this->status & self::STATUS_LOCKED)) {
            throw new \Error("Session must be locked with lock() before calling set()");
        }

        $this->data[$key] = $value;
    }

    /**
     * @param string $key
     *
     * @throws \Error If the session has not been locked or has been unlocked.
     */
    public function unset(string $key) {
        if (!($this->status & self::STATUS_LOCKED)) {
            throw new \Error("Session must be locked with lock() before calling unset()");
        }

        unset($this->data[$key]);
    }
}
