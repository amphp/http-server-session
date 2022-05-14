<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\Driver;
use Amp\Http\Server\Session\RedisStorage;
use Amp\Redis\RedisConfig;
use Amp\Redis\RemoteExecutorFactory;
use Amp\Redis\Sync\RedisMutex;

class RedisDriverTest extends DriverTest
{
    protected function createDriver(): Driver
    {
        $executorFactory = new RemoteExecutorFactory(RedisConfig::fromUri($this->getUri()));
        $executor = $executorFactory->createQueryExecutor();

        return new Driver(new RedisMutex($executor), new RedisStorage($executor));
    }

    final protected function getUri(): string
    {
        return \getenv('AMPHP_TEST_REDIS_INSTANCE') ?: 'redis://localhost';
    }
}
