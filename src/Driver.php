<?php

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;

final class Driver
{
    private KeyedMutex $mutex;
    private Storage $storage;
    private IdGenerator $idGenerator;

    public function __construct(KeyedMutex $mutex, Storage $storage, ?IdGenerator $generator = null)
    {
        $this->mutex = $mutex;
        $this->storage = $storage;
        $this->idGenerator = $generator ?? new DefaultIdGenerator;
    }

    public function create(?string $clientId): Session
    {
        return new Session($this->mutex, $this->storage, $this->idGenerator, $clientId);
    }
}
