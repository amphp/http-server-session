<?php

namespace Aerys\Session;

use Amp\Promise;

/**
 * When an operation fails, the driver must throw an Aerys\Session\Exception and try to clean up any locks regarding
 * that $id.
 */
interface Driver {
    /**
     * Determines if the given identifier could have been produced by the driver.
     *
     * @param string $id
     *
     * @return bool
     */
    public function validate(string $id): bool;

    /**
     * Creates a new locked session instance.
     *
     * @return Promise resolving to the new session ID.
     */
    public function open(): Promise;

    /**
     * Reloads the session contents.
     *
     * @param string $id The session identifier.
     *
     * @return Promise Resolving to an array with current session data.
     */
    public function read(string $id): Promise;

    /**
     * Saves and unlocks a session.
     *
     * @param string $id The session identifier.
     * @param array  $data Data to store, an empty array is equivalent to destruction of the session.
     * @param int    $ttl Time until session expiration, always > 0.
     *
     * @return Promise Resolving after success.
     */
    public function save(string $id, array $data, int $ttl): Promise;

    /**
     * Regenerates a session identifier, destroying the prior session and locking the new session.
     *
     * @param string $id The current session identifier.
     *
     * @return Promise Resolved with the new identifier.
     */
    public function regenerate(string $id): Promise;

    /**
     * Destroys a session with the given identifier.
     *
     * @param string $id
     *
     * @return \Amp\Promise
     */
    public function destroy(string $id): Promise;

    /**
     * Locks the session for writing.
     *
     * @param string $id The session identifier.
     *
     * @return Promise Resolving once successfully locked.
     */
    public function lock(string $id): Promise;

    /**
     * Unlocks the session, reloads data without saving.
     *
     * @param string $id The session identifier.
     *
     * @return Promise Resolves once successfully unlocked.
     */
    public function unlock(string $id): Promise;
}
