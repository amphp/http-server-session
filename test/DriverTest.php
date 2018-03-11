<?php

namespace Amp\Http\Server\Session\Test;

use Amp\ByteStream\Message;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server;
use Amp\Http\Server\Session\Session;
use Amp\PHPUnit\TestCase;
use League\Uri\Http;
use function Amp\call;
use function Amp\Promise\wait;

abstract class DriverTest extends TestCase {
    abstract protected function createDriver(): Server\Session\Driver;

    protected function respondWithSession(
        Server\Session\Driver $driver,
        callable $responder,
        string $sessionId = null
    ): Server\Response {
        return wait(call(function () use ($driver, $responder, $sessionId) {
            $responder = Server\Middleware\stack(
                new Server\CallableResponder($responder),
                new Server\Session\SessionMiddleware($driver)
            );

            $request = new Server\Request($this->createMock(Server\Client::class), "GET", Http::createFromString("/"));

            if ($sessionId !== null) {
                $request->setHeader('cookie', Server\Session\SessionMiddleware::DEFAULT_COOKIE_NAME . '=' . $sessionId);
            }

            return $responder->respond($request);
        }));
    }

    public function testNoCookieWithoutSessionData() {
        $response = $this->respondWithSession($this->createDriver(), function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            yield $session->save();

            return new Server\Response("hello world");
        });

        $this->assertNull($response->getHeader('set-cookie'));
    }

    public function testCookieGetsCreated() {
        $response = $this->respondWithSession($this->createDriver(), function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set("foo", "bar");
            yield $session->save();

            return new Server\Response("hello world");
        });

        $this->assertNotNull($response->getHeader('set-cookie'));
    }

    public function testPersistsData() {
        $driver = $this->createDriver();

        $response = $this->respondWithSession($driver, function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->open();
            $session->set("foo", "bar");
            yield $session->save();

            return new Server\Response("hello world");
        });

        $sessionCookie = ResponseCookie::fromHeader($response->getHeader("set-cookie"));
        $this->assertNotNull($sessionCookie);

        $response = $this->respondWithSession($driver, function (Server\Request $request) {
            /** @var Session $session */
            $session = yield $request->getAttribute(Session::class)->read();

            return new Server\Response($session->get("foo"));
        }, $sessionCookie->getValue());

        $this->assertSame("bar", wait(new Message($response->getBody())));
    }
}