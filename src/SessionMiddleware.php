<?php

namespace Amp\Http\Server\Session;

use Amp\Http;
use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Promise;
use function Amp\call;

final class SessionMiddleware implements Middleware
{
    public const DEFAULT_COOKIE_NAME = 'session';

    /** @var Driver */
    private $driver;

    /** @var string */
    private $cookieName;

    /** @var CookieAttributes */
    private $cookieAttributes;

    /** @var string */
    private $requestAttribute;

    /**
     * @param Driver                $driver
     * @param CookieAttributes|null $cookieAttributes Attribute set for session cookies.
     * @param string                $cookieName Name of session identifier cookie.
     * @param string                $requestAttribute Name of the request attribute being used to store the session.
     */
    public function __construct(
        Driver $driver,
        CookieAttributes $cookieAttributes = null,
        string $cookieName = self::DEFAULT_COOKIE_NAME,
        string $requestAttribute = Session::class
    ) {
        $this->driver = $driver;
        $this->cookieName = $cookieName;
        $this->cookieAttributes = $cookieAttributes ?? CookieAttributes::default()->withSameSite(CookieAttributes::SAMESITE_LAX);
        $this->requestAttribute = $requestAttribute;
    }

    /**
     * @param Request        $request
     * @param RequestHandler $responder Request responder.
     *
     * @return Promise<Response>
     */
    public function handleRequest(Request $request, RequestHandler $responder): Promise
    {
        return call(function () use ($request, $responder) {
            $cookie = $request->getCookie($this->cookieName);

            $originalId = $cookie ? $cookie->getValue() : null;
            $session = $this->driver->create($originalId);

            $request->setAttribute($this->requestAttribute, $session);

            $response = yield $responder->handleRequest($request);

            if (!$response instanceof Response) {
                throw new \TypeError('Request handler must resolve to an instance of ' . Response::class);
            }

            $response->onDispose([$session, 'unlockAll']);

            $id = $session->getId();

            if ($id === null && $originalId === null) {
                return $response;
            }

            if ($id === null || ($session->isRead() && $session->isEmpty())) {
                $attributes = $this->cookieAttributes->withExpiry(
                    new \DateTimeImmutable('@0', new \DateTimeZone('UTC'))
                );

                $response->setCookie(new ResponseCookie($this->cookieName, '', $attributes));
            } else {
                $response->setCookie(new ResponseCookie($this->cookieName, $id, $this->cookieAttributes));
            }

            $cacheControl = Http\parseFieldValueComponents($response, 'cache-control');

            if (empty($cacheControl)) {
                $response->setHeader('cache-control', 'private');
            } else {
                $tokens = [];
                foreach ($cacheControl as [$key, $value]) {
                    switch (\strtolower($key)) {
                        case 'public':
                        case 'private':
                            continue 2;

                        default:
                            $tokens[] = $value === '' ? $key : $key . '=' . $value;
                    }
                }

                $tokens[] = 'private';

                $response->setHeader('cache-control', \implode(',', $tokens));
            }

            return $response;
        });
    }
}
