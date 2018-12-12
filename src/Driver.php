<?php

namespace Amp\Http\Server\Session;

use Amp\Promise;

/**
 * When an operation fails, the driver must throw an Amp\Http\Server\Session\Exception and try to clean up any locks
 * regarding that $id.
 */
interface Driver
{
    /**
     * Determines if the given identifier matches the format produced by the driver.
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
    public function create(): Promise;

    /**
     * Reads the session contents.
     *
     * @param string $id The session identifier.
     *
     * @return Promise Resolving to an array with current session data.
     */
    public function read(string $id): Promise;

    /**
     * Saves a session.
     *
     * @param string   $id The session identifier.
     * @param mixed    $data Data to store.
     *
     * @return Promise Resolving after success.
     */
    public function save(string $id, array $data): Promise;

    /**
     * Lock an existing session for writing and return the current session data.
     *
     * @param string $id The session identifier.
     *
     * @return Promise Resolving with the session data once successfully locked.
     */
    public function lock(string $id): Promise;

    /**
     * Unlocks the session.
     *
     * @param string $id The session identifier.
     *
     * @return Promise Resolves once successfully unlocked.
     */
    public function unlock(string $id): Promise;
}
