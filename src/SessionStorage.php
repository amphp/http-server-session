<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

interface SessionStorage
{
    /**
     * Reads the session contents.
     *
     * @param string $id The session identifier.
     *
     * @return array Current session data.
     *
     * @throws SessionException
     */
    public function read(string $id): array;

    /**
     * Saves a session.
     *
     * @param string $id The session identifier.
     * @param array<string, string> $data Data to store.
     *
     * @throws SessionException
     */
    public function write(string $id, array $data): void;
}
