# http-server-session

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind. This package provides an [HTTP server](https://amphp.org/http-server) plugin that simplifies session management for your applications. Effortlessly handle user sessions, securely managing data across requests.

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-server-session
```

## Usage

#### Basic usage

To read data from the session is straightforward:

```php
$session->get('key'); // will read data stored in key 'key'
```

Note that `get()` will return `null` if the data in `key` are not found.

In order to write data, the session must be `lock()`ed first so that it cannot be written from anywhere else.

```php
$session->lock();
$session->set('key', $data);
$session->commit(); // commits & unlocks
```

Calling `commit()` will store the data in the session storage and unlock the session.

Other important methods of the `Session` class are:

```php
// regenerate the client id
$session->regenerate();

// force read from storage
$session->read();

// rollback what is `set()` in the session but has not been commit()ed yet
$session->rollback();

// destroy the session
$session->destroy();
```


#### Use the middleware to access Session in a RequestHandler

As this package is a plugin for [`amphp/http-server`](https://github.com/amphp/http-server) there is a middleware
implementation available that injects the `Session` instance into the attributes of the `Request`. When the middleware
is used the session is accessible from the attributes:

```php
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Session\Session;

class SomeRequestHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        /** @var Session $session */
        $session = $request->getAttribute(Session::class);

        // any operations on the session

        // return the response
    }
}
```

Note that if the attribute `Session::class` is not registered then `getAttribute` will throw a `MissingAttributeError`.

The middleware will handle setting and reading a session cookie in the request/response as well as releasing all locks
on the session after the request has been processed.

If you haven't used middleware in `amphp/http-server`, follow the [instructions on how to use middle ware with `amphp/http-server`](https://github.com/amphp/http-server#middleware).

A simple example is provided here [`examples/simple.php`](https://github.com/amphp/http-server-session/blob/3.x/examples/simple.php).

The `SessionMiddleware` can be further configured from the constructor regarding four different aspects:

* `SessionFactory`
* `CookieAttributes`
* Cookie name (default: `'session'`)
* Request attribute (default: `Session::class`)

The `CookieAttributes` is used to configure different cookie properties such as the expiry or the domain:

```php
$cookieAttributes = CookieAttributes::default()
    ->withDomain('amphp.org')
    ->withExpiry(new \DateTime('+30 min'))
    ->withSecure();
```

#### Using the factory to create an instance of Session

Internally the session works with 3 dependencies:

* [`KeyedMutex`](https://github.com/amphp/sync/blob/2.x/src/KeyedMutex.php) - A synchronisation primitive to be used across contexts
* [`SessionStorage`](https://github.com/amphp/http-server-session/blob/3.x/src/SessionStorage.php) - Interface for reading and writing data.
* [`SessionIdGenerator`](https://github.com/amphp/http-server-session/blob/3.x/src/SessionIdGenerator.php) - Interface for generating and validating
  a session ID.

An instance of the [`Session`](https://github.com/amphp/http-server-session/blob/3.x/src/Session.php#L28) can be constructed easily using
the provided [`SessionFactory`](https://github.com/amphp/http-server-session/blob/3.x/src/SessionFactory.php)

```php
/** @var \Amp\Http\Server\Session\SessionFactory $factory */
$session = $factory->create($clientId);
```

This library comes with two storage implementations

* LocalSessionStorage - Default
* RedisSessionStorage - Storage in Redis

and one session ID generator

* Base64UrlSessionIdGenerator

The constructor of the `SessionFactory` allows to configure the factory with other implementations, so that subsequent
calls to `create()` will use the new injected implementations. This can be beneficial in the certain scenarios, including
testing.

## Contributing

Please read [our rules](https://amphp.org/contributing) for details on our code of conduct, and the process for submitting pull requests to us.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
