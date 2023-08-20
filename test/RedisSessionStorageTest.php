<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\Redis\Sync\RedisMutex;
use function Amp\Redis\createRedisClient;

class RedisSessionStorageTest extends SessionStorageTest
{
    protected function createFactory(): SessionFactory
    {
        return new SessionFactory(
            new RedisMutex(createRedisClient($this->getUri())),
            new RedisSessionStorage(createRedisClient($this->getUri())),
        );
    }

    final protected function getUri(): string
    {
        return \getenv('AMPHP_TEST_REDIS_INSTANCE') ?: 'redis://localhost';
    }
}
