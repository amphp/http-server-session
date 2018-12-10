<?php

namespace Amp\Http\Server\Session\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server;
use Amp\Http\Server\Session\Session;
use Amp\PHPUnit\TestCase;
use League\Uri\Http;
use function Amp\call;
use function Amp\Promise\wait;

abstract class DriverTest extends TestCase
{
    abstract protected function createDriver(): Server\Session\Driver;

    protected function respondWithSession(
        Server\Session\Driver $driver,
        callable $requestHandler,
        string $sessionId = null
    ): Server\Response {
        return wait(call(function () use ($driver, $requestHandler, $sessionId) {
            $requestHandler = Server\Middleware\stack(
                new Server\RequestHandler\CallableRequestHandler($requestHandler),
                new Server\Session\SessionMiddleware($driver)
            );

            $request = new Server\Request($this->createMock(Server\Driver\Client::class), "GET", Http::createFromString("/"));

            if ($sessionId !== null) {
                $request->setHeader('cookie', Server\Session\SessionMiddleware::DEFAULT_COOKIE_NAME . '=' . $sessionId);
            }

            return $requestHandler->handleRequest($request);
        }));
    }

    public function testNoCookieWithoutSessionData()
    {
        $response = $this->respondWithSession($this->createDriver(), function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            yield $session->save();

            return new Server\Response(200, [], "hello world");
        });

        $this->assertNull($response->getHeader('set-cookie'));
    }

    public function testCookieGetsCreated()
    {
        $response = $this->respondWithSession($this->createDriver(), function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set("foo", "bar");
            yield $session->save();

            return new Server\Response(200, [], "hello world");
        });

        $this->assertNotNull($response->getHeader('set-cookie'));
    }

    public function testPersistsData()
    {
        $driver = $this->createDriver();

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
}
