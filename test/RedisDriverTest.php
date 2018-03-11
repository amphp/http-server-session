<?php

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server;
use Amp\Redis\Client;
use Kelunik\RedisMutex\Mutex;

class RedisDriverTest extends DriverTest {
    protected function createDriver(): Server\Session\Driver {
        if (!\getenv('AMP_HTTP_SERVER_SESSION_REDIS_TESTS')) {
            // Prevent tests from polluting a local Redis instance accidentally...
            $this->markTestSkipped('Please set the "AMP_HTTP_SERVER_SESSION_REDIS_TESTS" environment variable.');
        }

        return new Server\Session\RedisDriver(new Client("tcp://127.0.0.1:6379"), new Mutex("tcp://127.0.0.1:6379"));
    }
}
