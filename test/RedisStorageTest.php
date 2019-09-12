<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\RedisStorage;
use Amp\Http\Server\Session\Storage;
use Amp\Redis\Config;
use Amp\Redis\Mutex\Mutex;
use Amp\Redis\RemoteExecutorFactory;

class RedisStorageTest extends StorageTest
{
    protected function createStorage(): Storage
    {
        if (!\getenv('AMP_HTTP_SERVER_SESSION_REDIS_TESTS')) {
            // Prevent tests from polluting a local Redis instance accidentally...
            $this->markTestSkipped('Please set the "AMP_HTTP_SERVER_SESSION_REDIS_TESTS" environment variable.');
        }

        $factory = new RemoteExecutorFactory(Config::fromUri("redis://localhost"));

        return new RedisStorage($factory, new Mutex($factory));
    }
}
