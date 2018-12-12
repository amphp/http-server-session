<?php

namespace Amp\Http\Server\Session\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server;
use Amp\Http\Server\Session\Session;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\TimeoutException;
use League\Uri\Http;
use function Amp\call;
use function Amp\Promise\timeout;
use function Amp\Promise\wait;

abstract class StorageTest extends TestCase
{
    abstract protected function createStorage(): Server\Session\Storage;

    protected function respondWithSession(
        Server\Session\Storage $storage,
        callable $requestHandler,
        string $sessionId = null
    ): Server\Response {
        return wait(call(function () use ($storage, $requestHandler, $sessionId) {
            $requestHandler = Server\Middleware\stack(
                new Server\RequestHandler\CallableRequestHandler($requestHandler),
                new Server\Session\SessionMiddleware($storage)
            );

            $request = new Server\Request($this->createMock(Server\Driver\Client::class), "GET", Http::createFromString("/"));

            if ($sessionId !== null) {
                $request->setHeader('cookie', Server\Session\SessionMiddleware::DEFAULT_COOKIE_NAME . '=' . $sessionId);
            }

            return $requestHandler->handleRequest($request);
        }));
    }

    public function testNoCookieWithoutSessionData(): void
    {
        $response = $this->respondWithSession($this->createStorage(), function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            yield $session->save();

            return new Server\Response(200, [], "hello world");
        });

        $this->assertNull($response->getHeader('set-cookie'));
    }

    public function testCookieGetsCreated(): void
    {
        $response = $this->respondWithSession($this->createStorage(), function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set("foo", "bar");
            yield $session->save();

            return new Server\Response(200, [], "hello world");
        });

        $this->assertNotNull($response->getHeader('set-cookie'));
    }

    public function testPersistsData(): void
    {
        $driver = $this->createStorage();

        $response = $this->respondWithSession($driver, function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set("foo", "bar");
            yield $session->save();

            return new Server\Response(200, [], "hello world");
        });

        $sessionCookie = ResponseCookie::fromHeader($response->getHeader("set-cookie"));
        $this->assertNotNull($sessionCookie);

        $response = $this->respondWithSession($driver, function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->read();

            return new Server\Response(200, [], $session->get("foo"));
        }, $sessionCookie->getValue());

        $payload = new Payload($response->getBody());
        $this->assertSame("bar", wait($payload->buffer()));
    }

    public function testConcurrentLocking(): void
    {
        $driver = $this->createStorage();

        wait($driver->lock('a'));

        $this->expectException(TimeoutException::class);

        try {
            // dummy watcher to avoid "Loop stopped without resolving the promise"
            $watcher = Loop::delay(2000, function () {
                // do nothing
            });

            // should result in a timeout and never succeed, because there's already a lock
            wait(timeout($driver->lock('a'), 1000));
        } finally {
            Loop::cancel($watcher);
        }
    }
}
