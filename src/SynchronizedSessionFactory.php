<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;

final class SynchronizedSessionFactory implements SessionFactory
{
    public function __construct(
        private readonly KeyedMutex $mutex,
        private readonly SessionStorage $storage,
        private readonly SessionIdGenerator $idGenerator = new Base64UrlSessionIdGenerator(),
    ) {
    }

    public function create(?string $clientId): Session
    {
        return new SynchronizedSession($this->mutex, $this->storage, $this->idGenerator, $clientId);
    }
}
