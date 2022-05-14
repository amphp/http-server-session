<?php

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;

final class SessionFactory
{
    private readonly SessionIdGenerator $idGenerator;

    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly SessionStorage $storage,
        ?SessionIdGenerator $generator = null,
    ) {
        $this->idGenerator = $generator ?? new DefaultSessionIdGenerator;
    }

    public function create(?string $clientId): Session
    {
        return new Session($this->mutex, $this->storage, $this->idGenerator, $clientId);
    }
}
