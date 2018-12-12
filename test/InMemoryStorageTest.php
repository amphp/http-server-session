<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\Storage;
use Amp\Http\Server\Session\InMemoryStorage;

class InMemoryStorageTest extends StorageTest
{
    protected function createStorage(): Storage
    {
        return new InMemoryStorage;
    }
}
