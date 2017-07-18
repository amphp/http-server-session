# aerys-session

![Unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`amphp/aerys-session` is a non-blocking session handler for use with [`amphp/aerys`](https://github.com/amphp/aerys).

## Requirements

- PHP 7

## Installation

```bash
$ composer require amphp/aerys-session
```

## Registering the Session Middleware

```php
$redis = new Amp\Redis\Client("tcp://localhost:6379");
$mutex = new Amp\Redis\Mutex("tcp://localhost:6379");

$router->use(Aerys\session([
    "driver" => new Aerys\Session\Redis($redis, $mutex)
]));
```

## Using the Session

```php
public function respondToRequest(Request $request, Response $response) {
    $session = yield (new Aerys\Session($request))->read();

    if (!$session->has("user")) {
        $response
            ->setStatus(401)
            ->end("Unauthorised");
    }

    $user = $session->get("user");

    // ...

    $session->set("token", $token);
}
```

## Documentation

- [Official Documentation](http://amphp.org/aerys)
- [Getting Started with Aerys](http://blog.kelunik.com/2015/10/21/getting-started-with-aerys.html)
- [Getting Started with Aerys WebSockets](http://blog.kelunik.com/2015/10/20/getting-started-with-aerys-websockets.html)

## Security

If you discover any security related issues, please email bobwei9@hotmail.com or me@kelunik.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
