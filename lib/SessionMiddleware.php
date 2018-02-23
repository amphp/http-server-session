<?php

namespace Aerys\Session;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Promise;
use function Amp\call;

class SessionMiddleware implements Middleware {
    const DEFAULT_COOKIE_NAME = "SESSION_ID";

    /** @var \Aerys\Session\Driver */
    private $driver;

    /** @var string */
    private $cookieName;

    /** @var \Amp\Http\Cookie\CookieAttributes */
    private $cookieAttributes;

    /**
     * @param \Aerys\Session\Driver $driver
     * @param \Amp\Http\Cookie\CookieAttributes|null $cookieAttributes Attribute set for session cookies. Note that
     *     the setting for max-age will be overwritten based on the session TTL.
     * @param string $cookieName Name of session identifier cookie.
     */
    public function __construct(
        Driver $driver,
        CookieAttributes $cookieAttributes = null,
        string $cookieName = self::DEFAULT_COOKIE_NAME
    ) {
        $this->driver = $driver;
        $this->cookieName = $cookieName;
        $this->cookieAttributes = $cookieAttributes ?? CookieAttributes::default();
    }

    /**
     * @param Request $request
     * @param Responder $responder Request responder.
     *
     * @return Promise<\Aerys\Response>
     */
    public function process(Request $request, Responder $responder): Promise {
        return call(function () use ($request, $responder) {
            $cookie = $request->getCookie($this->cookieName);

            $id = $cookie ? $cookie->getValue() : null;

            if ($id !== null && !$this->driver->validate($id)) {
                $id = null;
            }

            $session = new Session($this->driver, $id);

            $request->setAttribute(Session::class, $session);

            $response = yield $responder->respond($request);

            if (!$response instanceof Response) {
                throw new \TypeError("Responder must resolve to an instance of " . Response::class);
            }

            if ($session->isDestroyed()) {
                $attributes = $this->cookieAttributes->withExpiry(
                    new \DateTimeImmutable("@0", new \DateTimeZone("UTC"))
                );

                $response->setCookie(new ResponseCookie($this->cookieName, '', $attributes));

                return $response;
            }

            if (!$session->isLocked()) {
                return $response;
            }

            $id = $session->getId();
            $ttl = $session->getTtl();

            if ($cookie === null || $cookie->getValue() !== $id) {
                if ($ttl === -1) {
                    $attributes = $this->cookieAttributes->withoutMaxAge();
                } else {
                    $attributes = $this->cookieAttributes->withMaxAge($ttl);
                }

                $response->setCookie(new ResponseCookie($this->cookieName, $id, $attributes));
            }

            yield $session->save();

            $cacheControl = $response->getHeaderArray("cache-control");

            if (empty($cacheControl)) {
                $response->setHeader("cache-control", "private");
            } else {
                foreach ($cacheControl as $key => $value) {
                    $tokens = \array_map("trim", \explode(",", $value));
                    $tokens = \array_filter($tokens, function ($token) {
                        return $token !== "public";
                    });

                    if (!\in_array("private", $tokens, true)) {
                        $tokens[] = "private";
                    }

                    $cacheControl[$key] = \implode(",", $tokens);
                }

                $response->setHeader("cache-control", $cacheControl);
            }

            return $response;
        });
    }
}
