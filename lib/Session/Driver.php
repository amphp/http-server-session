<?php

namespace Aerys\Session;

use Amp\Promise;

/*
 * When an operation fails, the driver must throw an Aerys\Session\Exception and try to clean up any locks regarding that $id
 */

interface Driver {
    /**
     * Creates a lock and reads the current session data
     *
     * @param string $id The session identifier
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function open(string $id): Promise;

    /**
     * Saves and unlocks a session
     *
     * @param string $id The session identifier
     * @param array $data to store (an empty array is equivalent to destruction of the session)
     * @param int $ttl time until session expiration (always > 0)
     * @return \Amp\Promise resolving after success
     */
    public function save(string $id, array $data, int $ttl): Promise;

    /**
     * Regenerates a session id
     *
     * @param string $oldId A old session identifier
     * @param string $newId A new session identifier, as replacement for $oldId
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(string $oldId, string $newId): Promise;

    /**
     * Reloads the session contents
     *
     * @param string $id The session identifier
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function read(string $id): Promise;

    /**
     * Unlocks the session, reloads data without saving
     *
     * @param string $id The session identifier
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function unlock(string $id): Promise;
}
