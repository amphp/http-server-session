<?php

namespace Amp\Http\Server\Session\Test;

use Amp\ByteStream\Payload;
use Amp\CancelledException;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server;
use Amp\Http\Server\Session\Session;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use League\Uri\Http;
use Revolt\EventLoop;
use function Amp\async;

abstract class SessionStorageTest extends AsyncTestCase
{
    public function testNoCookieWithoutSessionData(): void
    {
        $response = $this->respondWithSession($this->createDriver(), static function (Server\Request $request) {
            /** @var Session $session */
            $session = $request->getAttribute(Session::class)->open();
            $session->save();

            return new Server\Response(200, [], 'hello world');
        });

        $this->assertNull($response->getHeader('set-cookie'));
    }

    public function testCookieGetsCreated(): void
    {
        $response = $this->respondWithSession($this->createDriver(), static function (Server\Request $request) {
            /** @var Session $session */
            $session = $request->getAttribute(Session::class)->open();
            $session->set('foo', 'bar');
            $session->save();

            return new Server\Response(200, [], 'hello world');
        });

        $this->assertNotNull($response->getHeader('set-cookie'));
    }

    public function testCircularReference(): void
    {
        $this->setTimeout(1000);

        $driver = $this->createDriver();

        $session = $driver->create((new Server\Session\DefaultSessionIdGenerator)->generate());
        $session->open();

        \gc_collect_cycles();
        $session = null;
        $this->assertSame(0, \gc_collect_cycles());
    }

    public function testPersistsData(): void
    {
        $driver = $this->createDriver();

        $response = $this->respondWithSession($driver, static function (Server\Request $request) {
            $session = $request->getAttribute(Session::class)->open();
            $session->set('foo', 'bar');
            $session->save();

            return new Server\Response(200, [], 'hello world');
        });

        $sessionCookie = ResponseCookie::fromHeader($response->getHeader('set-cookie'));
        $this->assertNotNull($sessionCookie);
        $this->assertNotEmpty($sessionCookie->getValue());

        $response = $this->respondWithSession($driver, static function (Server\Request $request) {
            $session = $request->getAttribute(Session::class)->read();

            return new Server\Response(200, [], $session->get('foo'));
        }, $sessionCookie->getValue());

        $payload = new Payload($response->getBody());
        $this->assertSame('bar', $payload->buffer());
    }

    public function testConcurrentLocking(): void
    {
        $sessionId = (new Server\Session\DefaultSessionIdGenerator)->generate();

        $driver = $this->createDriver();
        $sessionA = $driver->create($sessionId);
        $sessionB = $driver->create($sessionId);

        $this->assertFalse($sessionA->isRead());
        $this->assertFalse($sessionA->isLocked());

        $sessionA->open();

        $this->assertTrue($sessionA->isRead());
        $this->assertTrue($sessionA->isLocked());

        $this->expectException(CancelledException::class);

        // dummy watcher to avoid "Event loop terminated without resuming the current suspension"
        $watcher = EventLoop::delay(2, static fn () => null);

        try {
            // should result in a timeout and never succeed, because there's already a lock
            async(fn () => $sessionB->open())->await(new TimeoutCancellation(1));
        } finally {
            EventLoop::cancel($watcher);

            $sessionA->unlock();
            $sessionB->unlock();
        }
    }

    abstract protected function createDriver(): Server\Session\SessionFactory;

    protected function respondWithSession(
        Server\Session\SessionFactory $driver,
        callable $requestHandler,
        string $sessionId = null
    ): Server\Response {
        $requestHandler = Server\Middleware\stack(
            new Server\RequestHandler\ClosureRequestHandler($requestHandler),
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
    }
}
