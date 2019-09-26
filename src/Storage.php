<?php

namespace Amp\Http\Server\Session;

use Amp\Promise;

interface Storage
{
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
     * @param string $id The session identifier.
     * @param mixed  $data Data to store.
     *
     * @return Promise Resolving after success.
     */
    public function write(string $id, array $data): Promise;
}
