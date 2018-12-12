<?php


namespace Amp\Http\Server\Session;

interface Serializer
{
    public function serialize(int $ttl, array $data): string;

    public function unserialize(string $data = null, &$ttl = null): array;
}
