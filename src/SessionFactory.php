<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use Amp\Sync\KeyedMutex;
use Amp\Sync\LocalKeyedMutex;

final class SessionFactory
{
    public function __construct(
        private readonly KeyedMutex $mutex = new LocalKeyedMutex(),
        private readonly SessionStorage $storage = new LocalSessionStorage(),
        private readonly SessionIdGenerator $idGenerator = new Base64UrlSessionIdGenerator(),
    ) {
    }

    public function create(?string $clientId): Session
    {
        return new SynchronizedSession($this->mutex, $this->storage, $this->idGenerator, $clientId);
    }
}
