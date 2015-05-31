<?php

namespace Aerys;

/**
 * @param array $config must contain driver
 * @return Middleware to be used with Router or Host::use()
 */
function session(array $config = []) {
    assert(isset($config["driver"]) && $config["driver"] instanceof SessionDriver);

    return new class($config) extends Middleware {
        private $config;

        public function __construct($config) {
            $this->config = $config;
        }

        public function filter(InternalRequest $request) {
            $request->locals["aerys.session.config"] = $this->config;

            $message = "";
            $headerEndOffset = null;
            do {
                $message .= ($part = yield);
            } while ($part !== Filter::END && ($headerEndOffset = \strpos($message, "\r\n\r\n")) === false);
            if (!isset($headerEndOffset)) {
                return $message;
            }

            $sessionId = $request->locals["aerys.session.id"] ?? null;
            if (!isset($sessionId)) {
                return $message;
            }

            $startLineAndHeaders = substr($message, 0, $headerEndOffset);
            list($startLine, $headers) = explode("\r\n", $startLineAndHeaders, 2);
            $body = substr($message, $headerEndOffset + 4);

            $config = $request->locals["aerys.session.config"];

            if (!isset($config["cookie_flags"])) {
                $config["cookie_flags"] = $request->isEncrypted ? ["secure"] : [];
            }

            if ($sessionId === false) {
                $cookie = "{$this->config["name"]}=deleted; Expires=Thu, 01 Jan 1970 00:00:00 GMT";
            } else {
                $cookie = "{$this->config["name"]}=$sessionId";
                if ($config["ttl"] >= 0) {
                    $cookie .= "; Expires=" . date(\DateTime::RFC1123, time() + $config["ttl"]);
                }
            }

            foreach ($this->config["cookie_flags"] as $name => $value) {
                if (is_int($name)) {
                    $cookie .= "; $value";
                } else {
                    $cookie .= "; $name=$value";
                }
            }

            $headers = addHeader($headers, "Set-Cookie", $cookie);

            return "{$startLine}\r\n{$headers}\r\n\r\n{$body}";
        }
    };
}
