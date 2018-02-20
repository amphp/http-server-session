<?php

namespace Aerys\Session;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
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
     * @param string $cookieName Name of session identifier cookie.
     * @param \Amp\Http\Cookie\CookieAttributes|null c$cookieAttributes Attribute set for session cookies. Note that
     *     the setting for max-age will be overwritten based on the session TTL.
     */
    public function __construct(
        Driver $driver,
        string $cookieName = self::DEFAULT_COOKIE_NAME,
        CookieAttributes $cookieAttributes = null
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

            if ($cookie === null) {
                $session = yield $this->driver->open();
            } else {
                $session = yield $this->driver->read($cookie->getValue());
            }

            \assert(
                $session instanceof Session,
                \get_class($this->driver) . " must produce an instance of " . Session::class
            );

            $request->setAttribute(Session::class, $session);

            /** @var \Aerys\Response $response */
            $response = yield $responder->respond($request);

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

            if ($session->isDestroyed()) {
                $attributes = $this->cookieAttributes->withExpiry(
                    new \DateTimeImmutable("@0", new \DateTimeZone("UTC"))
                );

                $response->setCookie(new ResponseCookie($this->cookieName, '', $attributes));

                return $response;
            }

            $id = $session->getId();
            $data = $session->getData();
            $ttl = $session->getTtl();

            if ($cookie === null || $cookie->getValue() !== $id) {
                if ($ttl === -1) {
                    $attributes = $this->cookieAttributes->withoutMaxAge();
                } else {
                    $attributes = $this->cookieAttributes->withMaxAge($ttl);
                }

                $response->setCookie(new ResponseCookie($this->cookieName, $id, $attributes));
            }

            if (!$session->isUnlocked()) {
                yield $this->driver->save($id, $data, $ttl);
            }

            return $response;
        });
    }
}
