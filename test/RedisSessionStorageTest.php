<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\RedisSessionStorage;
use Amp\Http\Server\Session\SessionFactory;
use Amp\Redis\RedisConfig;
use Amp\Redis\RemoteExecutorFactory;
use Amp\Redis\Sync\RedisMutex;

class RedisSessionStorageTest extends SessionStorageTest
{
    protected function createDriver(): SessionFactory
    {
        $executorFactory = new RemoteExecutorFactory(RedisConfig::fromUri($this->getUri()));
        $executor = $executorFactory->createQueryExecutor();

        return new SessionFactory(new RedisMutex($executor), new RedisSessionStorage($executor));
    }

    final protected function getUri(): string
    {
        return \getenv('AMPHP_TEST_REDIS_INSTANCE') ?: 'redis://localhost';
    }
}
