<?php /** @noinspection PhpUndefinedClassInspection */ declare(strict_types=1);

namespace Amp\Http\Server\Session;

interface Session
{
    /**
     * @return string|null Session identifier.
     */
    public function getId(): ?string;

    /**
     * @return bool `true` if session data has been read.
     */
    public function isRead(): bool;

    /**
     * @return bool `true` if the session has been locked.
     */
    public function isLocked(): bool;

    /**
     * @return bool `true` if the session is empty.
     *
     * @throws \Error If the session has not been read.
     */
    public function isEmpty(): bool;

    /**
     * Regenerates a session identifier.
     *
     * @return string Returns the new session identifier.
     */
    public function regenerate(): string;

    /**
     * Reads the session data without locking the session.
     */
    public function read(): self;

    /**
     * Locks the session for writing.
     *
     * This will implicitly reload the session data from the storage.
     */
    public function lock(): self;

    /**
     * Saves the given data in the session and unlocks it.
     *
     * The session must be locked with lock() before calling this method.
     */
    public function commit(): void;

    /**
     * Reloads the data from the storage discarding modifications and unlocks the session.
     *
     * The session must be locked with lock() before calling this method.
     */
    public function rollback(): void;

    /**
     * Unlocks the session.
     *
     * The session must be locked with lock() before calling this method.
     */
    public function unlock(): void;

    /**
     * Destroys and unlocks the session data.
     *
     * @throws \Error If the session has not been locked for writing.
     */
    public function destroy(): void;

    /**
     * Releases all locks on the session.
     */
    public function unlockAll(): void;

    /**
     * @throws \Error If the session has not been read.
     */
    public function has(string $key): bool;

    /**
     * @throws \Error If the session has not been read.
     */
    public function get(string $key): ?string;

    /**
     * @throws \Error If the session has not been opened for writing.
     */
    public function set(string $key, mixed $data): void;

    /**
     * @throws \Error If the session has not been locked for writing.
     */
    public function unset(string $key): void;

    /**
     * @throws \Error If the session has not been read.
     */
    public function getData(): array;
}
