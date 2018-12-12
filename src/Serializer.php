<?php


namespace Amp\Http\Server\Session;

interface Serializer
{
    public function serialize(array $data): string;

    public function unserialize(string $data): array;
}
