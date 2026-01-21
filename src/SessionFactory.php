<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

interface SessionFactory
{
    /**
     * @param string|null $clientId Session cookie value.
     */
    public function create(?string $clientId): Session;
}
