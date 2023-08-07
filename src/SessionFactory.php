<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

interface SessionFactory
{
    public function create(?string $clientId): Session;
}
