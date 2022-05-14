<?php

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;

final class Driver
{
    private readonly IdGenerator $idGenerator;

    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly Storage $storage,
        ?IdGenerator $generator = null,
    ) {
        $this->idGenerator = $generator ?? new DefaultIdGenerator;
    }

    public function create(?string $clientId): Session
    {
        return new Session($this->mutex, $this->storage, $this->idGenerator, $clientId);
    }
}
