<?php

namespace Amp\Http\Server\Session;

use Amp\Http;
use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class SessionMiddleware implements Middleware
{
    public const DEFAULT_COOKIE_NAME = 'session';

    private Driver $driver;

    private string $cookieName;

    private CookieAttributes $cookieAttributes;

    private string $requestAttribute;

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
     * @return Response
     */
    public function handleRequest(Request $request, RequestHandler $responder): Response
    {
        $cookie = $request->getCookie($this->cookieName);

        $originalId = $cookie ? $cookie->getValue() : null;
        $session = $this->driver->create($originalId);

        $request->setAttribute($this->requestAttribute, $session);

        $response = $responder->handleRequest($request);

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
    }
}
