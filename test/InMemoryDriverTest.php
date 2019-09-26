<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\Driver;
use Amp\Http\Server\Session\InMemoryStorage;
use Amp\Http\Server\Session\LocalKeyedMutex;

class InMemoryDriverTest extends DriverTest
{
    protected function createDriver(): Driver
    {
        return new Driver(new LocalKeyedMutex, new InMemoryStorage);
    }
}
