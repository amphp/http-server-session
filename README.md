# aerys-session

![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`amphp/aerys-session` is a non-blocking session handler for use with [`amphp/aerys`](https://github.com/amphp/aerys).

## Requirements

- PHP 7

## Installation

```bash
composer require amphp/aerys-session
```

## Registering the Session Middleware

```php
$redis = new Amp\Redis\Client("tcp://localhost:6379");
$mutex = new Amp\Redis\Mutex("tcp://localhost:6379");

$router->use(
    Aerys\session(new Aerys\Session\Redis($redis, $mutex))
);
```

## Using the Session

```php
public function respondToRequest(Request $request, Response $response) {
    $session = new Aerys\Session($request);

    // you need to read the session before you can access the data from it
    yield $session->read();

    if (!$session->has("user")) {
        $response
            ->setStatus(401)
            ->end("Unauthorised");

        return;
    }

    $user = $session->get("user");

    // ...

    // you need to open the session for writing before you can write to it
    yield $session->open();

    $session->set("token", $token);

    // don't forget to save the session...
    yield $session->save();
}
```

## Documentation

- [Official Documentation](http://amphp.org/aerys)
- [Getting Started with Aerys](http://blog.kelunik.com/2015/10/21/getting-started-with-aerys.html)

## Security

If you discover any security related issues, please email bobwei9@hotmail.com or me@kelunik.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
