<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace Amp\Http\Server\Session\Test;

use Amp\Http\Server\Session\Driver;
use Amp\Http\Server\Session\RedisStorage;
use Amp\Redis\Config;
use Amp\Redis\Mutex\Mutex;
use Amp\Redis\RemoteExecutorFactory;

class RedisDriverTest extends DriverTest
{
    protected function createDriver(): Driver
    {
        if (!\getenv('AMP_HTTP_SERVER_SESSION_REDIS_TESTS')) {
            // Prevent tests from polluting a local Redis instance accidentally...
            $this->markTestSkipped('Please set the "AMP_HTTP_SERVER_SESSION_REDIS_TESTS" environment variable.');
        }

        $executorFactory = new RemoteExecutorFactory(Config::fromUri('redis://'));

        return new Driver(new Mutex($executorFactory), new RedisStorage($executorFactory));
    }
}
