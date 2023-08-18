<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\ByteStream\Payload;
use Amp\CancelledException;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\HttpStatus;
use Amp\Http\Server;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use League\Uri\Http;
use Revolt\EventLoop;
use function Amp\async;

abstract class SessionStorageTest extends AsyncTestCase
{
    public function testNoCookieWithoutSessionData(): void
    {
        $response = $this->respondWithSession($this->createFactory(), static function (Server\Request $request) {
            /** @var Session $session */
            $session = $request->getAttribute(Session::class)->open();
            $session->save();

            return new Server\Response(200, body: 'hello world');
        });

        self::assertNull($response->getHeader('set-cookie'));
    }

    public function testCookieGetsCreated(): void
    {
        $response = $this->respondWithSession($this->createFactory(), static function (Server\Request $request) {
            /** @var Session $session */
            $session = $request->getAttribute(Session::class)->open();
            $session->set('foo', 'bar');
            $session->save();

            return new Server\Response(HttpStatus::OK, ['cache-control' => 'public, max-age=604800'], 'hello world');
        });

        self::assertNotNull($response->getHeader('set-cookie'));
        self::assertStringContainsString('max-age=604800', $response->getHeader('cache-control'));
        self::assertStringContainsString('private', $response->getHeader('cache-control'));
    }

    public function testCircularReference(): void
    {
        $this->setTimeout(1);

        $driver = $this->createFactory();

        $session = $driver->create((new Server\Session\DefaultSessionIdGenerator)->generate());
        $session->open();

        \gc_collect_cycles();
        $session = null;
        self::assertSame(0, \gc_collect_cycles());
    }

    public function testPersistsData(): void
    {
        $driver = $this->createFactory();

        $response = $this->respondWithSession($driver, static function (Server\Request $request) {
            $session = $request->getAttribute(Session::class)->open();
            $session->set('foo', 'bar');
            $session->save();

            return new Server\Response(HttpStatus::OK, [], 'hello world');
        });

        $sessionCookie = ResponseCookie::fromHeader($response->getHeader('set-cookie'));
        self::assertNotNull($sessionCookie);
        self::assertNotEmpty($sessionCookie->getValue());

        $response = $this->respondWithSession($driver, static function (Server\Request $request) {
            $session = $request->getAttribute(Session::class)->read();

            return new Server\Response(HttpStatus::OK, body: $session->get('foo'));
        }, $sessionCookie->getValue());

        $payload = new Payload($response->getBody());
        self::assertSame('bar', $payload->buffer());
    }

    public function testConcurrentLocking(): void
    {
        $sessionId = (new Server\Session\DefaultSessionIdGenerator)->generate();

        $driver = $this->createFactory();
        $sessionA = $driver->create($sessionId);
        $sessionB = $driver->create($sessionId);

        self::assertFalse($sessionA->isRead());
        self::assertFalse($sessionA->isLocked());

        $sessionA->open();

        self::assertTrue($sessionA->isRead());
        self::assertTrue($sessionA->isLocked());

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

    abstract protected function createFactory(): Server\Session\SessionFactory;

    /**
     * @param \Closure(Request):Response $requestHandler
     */
    protected function respondWithSession(
        Server\Session\SessionFactory $driver,
        \Closure $requestHandler,
        string $sessionId = null
    ): Server\Response {
        $requestHandler = Server\Middleware\stackMiddleware(
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
