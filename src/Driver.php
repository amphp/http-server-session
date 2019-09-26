<?php

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;

final class Driver
{
    /** @var KeyedMutex */
    private $mutex;
    /** @var Storage */
    private $storage;
    /** @var IdGenerator */
    private $idGenerator;

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
