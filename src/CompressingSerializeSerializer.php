<?php

namespace Amp\Http\Server\Session;

final class CompressingSerializeSerializer implements Serializer
{
    private const FLAG_COMPRESSED = 1;
    private const COMPRESSION_THRESHOLD = 256;

    public function serialize(array $data): string
    {
        $serializedData = \serialize($data);

        $flags = 0;

        if (\strlen($serializedData) > self::COMPRESSION_THRESHOLD) {
            $serializedData = \gzdeflate($serializedData, 1);
            $flags |= self::FLAG_COMPRESSED;
        }

        return \chr($flags & 0xff) . $serializedData;
    }

    public function unserialize(string $data): array
    {
        $firstByte = \ord($data[0]);
        $data = \substr($data, 1);

        if ($firstByte & self::FLAG_COMPRESSED) {
            $data = \gzinflate($data);
        }

        return \unserialize($data, ['allowed_classes' => true]);
    }
}
