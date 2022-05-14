<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\LocalSessionStorage;
use Amp\Http\Server\Session\SessionFactory;
use Amp\Sync\LocalKeyedMutex;

class LocalSessionStorageTest extends SessionStorageTest
{
    protected function createFactory(): SessionFactory
    {
        return new SessionFactory(new LocalKeyedMutex, new LocalSessionStorage);
    }
}
