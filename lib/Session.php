<?php

namespace Aerys\Session;

use function Amp\call;
use Amp\Promise;

class Session {
    const STATUS_LOCKED = 0;
    const STATUS_UNLOCKED = 1;
    const STATUS_DESTROYED = 2;

    const DEFAULT_TTL = 3600;

    /** @var \Aerys\Session\Driver */
    private $driver;

    /** @var string */
    private $id;

    /** @var string[] */
    private $data;

    /** @var int */
    private $ttl = self::DEFAULT_TTL;

    /** @var int */
    private $status = self::STATUS_LOCKED;

    public function __construct(Driver $driver, string $id, int $ttl, array $data = []) {
        $this->driver = $driver;
        $this->id = $id;
        $this->data = $data;
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

    /**
     * @return bool `true` if the session has been unlocked.
     */
    public function isDestroyed(): bool {
        return $this->status & self::STATUS_DESTROYED;
    }

    /**
     * @return bool `true` if the session has been unlocked.
     */
    public function isUnlocked(): bool {
        return $this->status & self::STATUS_UNLOCKED;
    }

    /**
     * Regenerates a session identifier.
     *
     * @return Promise Resolving with the new session identifier.
     */
    public function regenerate(): Promise {
        return call(function () {
            return $this->id = yield $this->driver->regenerate($this->id);
        });
    }

    /**
     * @return string[] All data currently stored in the session.
     */
    public function getData(): array {
        return $this->data;
    }

    public function save(): Promise {
        if ($this->status & self::STATUS_UNLOCKED) {
            throw new \Error("Cannot save an unlocked session");
        }

        $this->status |= self::STATUS_UNLOCKED;
        return $this->driver->save($this->id, $this->data, $this->ttl);
    }

    /**
     * Unlocks and destroys the session.
     *
     * @return Promise Resolving after success.
     */
    public function destroy(): Promise {
        $this->status |= self::STATUS_DESTROYED | self::STATUS_UNLOCKED;
        return $this->driver->destroy($this->id);
    }

    /**
     * Unlocks the session and discards any changes made to the session instance.
     *
     * @return \Amp\Promise
     */
    public function unlock(): Promise {
        $this->status |= self::STATUS_UNLOCKED;
        return $this->driver->unlock($this->id);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool {
        return \array_key_exists($key, $this->data);
    }

    /**
     * @param string $key
     *
     * @return string|null
     */
    public function get(string $key) {
        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function set(string $key, string $value) {
        $this->data[$key] = $value;
    }

    /**
     * @param string $key
     */
    public function unset(string $key) {
        unset($this->data[$key]);
    }
}
