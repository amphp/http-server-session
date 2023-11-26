<?php

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Session\Session;
use Amp\Http\Server\Session\SessionMiddleware;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\InternetAddress;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

use function Amp\ByteStream\getStdout;
use function Amp\Http\Server\Middleware\stackMiddleware;
use function Amp\trapSignal;

require \dirname(__DIR__) . '/vendor/autoload.php';

/*
 * Example requires:
 *
 * ext-pcntl
 * amphp/log
 * amphp/byte-stream
 */

$logHandler = new StreamHandler(getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);

$server->expose(new InternetAddress("0.0.0.0", 1337));
$server->expose(new InternetAddress("[::]", 1337));

$errorHandler = new DefaultErrorHandler();

$requestHandler = new ClosureRequestHandler(
    function (Request $request): Response
    {
        /** @var Session $session */
        $session = $request->getAttribute(Session::class);
        $counter = $session->get('counter') ?? 0;

        // Checking path just so we do not increase on `/favicon.ico` requests.
        if ($request->getUri()->getPath() === '/') {
            $counter++;

            $session->lock();
            $session->set('counter', $counter);
            $session->commit();     // commits & unlocks
        }

        $message = 'Hit counters are cool: ' . $counter;
        return new Response(HttpStatus::OK, ["content-type" => "text/plain; charset=utf-8"], $message);
    }
);

$sessionMiddleware = new SessionMiddleware();

$middlewareStack = stackMiddleware($requestHandler, $sessionMiddleware);

$server->start($middlewareStack, $errorHandler);

// Await a termination signal to be received.
$signal = trapSignal([\SIGINT, \SIGTERM]);

$server->stop();
