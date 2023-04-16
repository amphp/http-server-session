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

    private readonly CookieAttributes $cookieAttributes;

    /**
     * @param CookieAttributes|null $cookieAttributes Attribute set for session cookies.
     * @param non-empty-string $cookieName Name of session identifier cookie.
     * @param non-empty-string $requestAttribute Name of the request attribute being used to store the session.
     *      Defaults to {@see Session::class}.
     */
    public function __construct(
        private readonly SessionFactory $factory,
        CookieAttributes $cookieAttributes = null,
        private readonly string $cookieName = self::DEFAULT_COOKIE_NAME,
        private readonly string $requestAttribute = Session::class
    ) {
        $this->cookieAttributes = $cookieAttributes
            ?? CookieAttributes::default()->withSameSite(CookieAttributes::SAMESITE_LAX);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $cookie = $request->getCookie($this->cookieName);

        $originalId = $cookie?->getValue();
        $session = $this->factory->create($originalId);

        $request->setAttribute($this->requestAttribute, $session);

        $response = $requestHandler->handleRequest($request);

        $response->onDispose($session->unlockAll(...));

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

        $cacheControl = Http\parseMultipleHeaderFields($response, 'cache-control') ?? [];

        $tokens = [];
        foreach ($cacheControl as [$key, $value]) {
            assert(is_string($key));
            $tokens[] = match (\strtolower($key)) {
                'public', 'private' => null,
                default => $value === '' ? $key : $key . '=' . $value,
            };
        }

        $tokens[] = 'private';

        $response->setHeader('cache-control', \implode(',', \array_filter($tokens)));

        return $response;
    }
}
