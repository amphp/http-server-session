<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\Sync\LocalKeyedMutex;

class LocalSessionStorageTest extends SessionStorageTest
{
    protected function createFactory(): SessionFactory
    {
        return new SynchronizedSessionFactory(new LocalKeyedMutex, new LocalSessionStorage);
    }
}
