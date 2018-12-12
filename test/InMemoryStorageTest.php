<?php


namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\Storage;
use Amp\Http\Server\Session\InMemoryStorage;

class InMemoryStorageTest extends StorageTest
{
    protected function createDriver(): Storage
    {
        return new InMemoryStorage;
    }
}
