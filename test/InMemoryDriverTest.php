<?php


namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\Driver;
use Amp\Http\Server\Session\InMemoryDriver;

class InMemoryDriverTest extends DriverTest
{
    protected function createDriver(): Driver
    {
        return new InMemoryDriver;
    }
}
