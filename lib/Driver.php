<?php

namespace Aerys\Session;

use Amp\Promise;

interface Driver {
    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function open(string $id): Promise;

    /**
     * Saves and unlocks a session
     * @param array $data to store (an empty array is equivalent to destruction of the session)
     * @param int $ttl time until session expiration (always > 0)
     * @return \Amp\Promise resolving after success
     */
    public function save(string $id, array $data, int $ttl): Promise;

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(string $oldId, string $newId): Promise;

    /**
     * Reloads the session contents
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function read(string $id): Promise;

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving to an array with current session data
     */
    public function unlock(string $id): Promise;
}
