<?php

namespace Aerys;

/**
 * @param array $config must contain driver
 * @return Middleware to be used with Router or Host::use()
 */
function session(array $config = []) {
    assert(isset($config["driver"]) && $config["driver"] instanceof Session\Driver);

    return new class($config) implements Middleware {
        private $config;

        public function __construct($config) {
            $this->config = $config;
        }

        public function do(InternalRequest $request) {
            $request->locals["aerys.session.config"] = $this->config;

            $headers = yield;

            $sessionId = $request->locals["aerys.session.id"] ?? null;

            if (!isset($sessionId)) {
                return $headers;
            }

            $config = $request->locals["aerys.session.config"];

            if (!isset($config["cookie_flags"])) {
                $config["cookie_flags"] = $request->client->isEncrypted ? ["secure", "httpOnly"] : ["httpOnly"];
            }

            if ($sessionId === false) {
                $cookie = "{$config["name"]}=; Expires=Thu, 01 Jan 1970 00:00:00 GMT";
            } else {
                $cookie = "{$config["name"]}=$sessionId";

                if ($config["ttl"] >= 0) {
                    $cookie .= "; Expires=" . date(\DateTime::RFC1123, time() + $config["ttl"]);
                }
            }

            foreach ($config["cookie_flags"] as $name => $value) {
                if (is_int($name)) {
                    $cookie .= "; $value";
                } else {
                    $cookie .= "; $name=$value";
                }
            }

            $cookie .= "; Path={$config["path"]}";

            $headers["set-cookie"][] = $cookie;

            if (isset($headers["cache-control"])) {
                foreach ($headers["cache-control"] as $key => $value) {
                    $tokens = array_map("trim", explode(",", $value));
                    $tokens = array_filter($tokens, function($token) {
                        return $token !== "public";
                    });

                    if (!in_array("private", $tokens, true)) {
                        $tokens[] = "private";
                    }

                    $headers["cache-control"][$key] = implode(",", $tokens);
                }
            } else {
                $headers["cache-control"][] = "private";
            }

            return $headers;
        }
    };
}
