<?php

namespace Amp\Http\Server\Session\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server;
use Amp\Http\Server\Session\Session;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\TimeoutException;
use League\Uri\Http;
use function Amp\call;
use function Amp\Promise\timeout;

abstract class DriverTest extends AsyncTestCase
{
    public function testNoCookieWithoutSessionData(): \Generator
    {
        /** @var Server\Response $response */
        $response = yield $this->respondWithSession($this->createDriver(), static function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            yield $session->save();

            return new Server\Response(200, [], 'hello world');
        });

        $this->assertNull($response->getHeader('set-cookie'));
    }

    public function testCookieGetsCreated(): \Generator
    {
        /** @var Server\Response $response */
        $response = yield $this->respondWithSession($this->createDriver(), static function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set('foo', 'bar');
            yield $session->save();

            return new Server\Response(200, [], 'hello world');
        });

        $this->assertNotNull($response->getHeader('set-cookie'));
    }

    public function testPersistsData(): \Generator
    {
        $driver = $this->createDriver();

        /** @var Server\Response $response */
        $response = yield $this->respondWithSession($driver, static function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set('foo', 'bar');
            yield $session->save();

            return new Server\Response(200, [], 'hello world');
        });

        $sessionCookie = ResponseCookie::fromHeader($response->getHeader('set-cookie'));
        $this->assertNotNull($sessionCookie);
        $this->assertNotEmpty($sessionCookie->getValue());

        /** @var Server\Response $response */
        $response = yield $this->respondWithSession($driver, static function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->read();

            return new Server\Response(200, [], $session->get('foo'));
        }, $sessionCookie->getValue());

        $payload = new Payload($response->getBody());
        $this->assertSame('bar', yield $payload->buffer());
    }

    public function testConcurrentLocking(): \Generator
    {
        $sessionId = (new Server\Session\DefaultIdGenerator)->generate();

        $driver = $this->createDriver();
        $sessionA = $driver->create($sessionId);
        $sessionB = $driver->create($sessionId);

        $this->assertFalse($sessionA->isRead());
        $this->assertFalse($sessionA->isLocked());

        yield $sessionA->open();

        $this->assertTrue($sessionA->isRead());
        $this->assertTrue($sessionA->isLocked());

        $this->expectException(TimeoutException::class);

        try {
            // dummy watcher to avoid "Loop stopped without resolving the promise"
            $watcher = Loop::delay(2000, static function () {
                // do nothing
            });

            // should result in a timeout and never succeed, because there's already a lock
            yield timeout($sessionB->open(), 1000);
        } finally {
            Loop::cancel($watcher);

            $sessionA->unlock();
            $sessionB->unlock();
        }
    }

    abstract protected function createDriver(): Server\Session\Driver;

    protected function respondWithSession(
        Server\Session\Driver $driver,
        callable $requestHandler,
        string $sessionId = null
    ): Promise {
        return call(function () use ($driver, $requestHandler, $sessionId) {
            $requestHandler = Server\Middleware\stack(
                new Server\RequestHandler\CallableRequestHandler($requestHandler),
                new Server\Session\SessionMiddleware($driver)
            );

            $request = new Server\Request(
                $this->createMock(Server\Driver\Client::class),
                'GET',
                Http::createFromString('/')
            );

            if ($sessionId !== null) {
                $request->setHeader('cookie', Server\Session\SessionMiddleware::DEFAULT_COOKIE_NAME . '=' . $sessionId);
            }

            return $requestHandler->handleRequest($request);
        });
    }
}
