<?php


namespace Amp\Http\Server\Session;

class CompressingSerializeSerializer implements Serializer
{
    const FLAG_COMPRESSED = 1;
    const COMPRESSION_THRESHOLD = 256;


    public function serialize(int $ttl, array $data): string
    {
        $serializedData = \serialize([$ttl, $data]);

        $flags = 0;

        if (\strlen($serializedData) > self::COMPRESSION_THRESHOLD) {
            $serializedData = \gzdeflate($serializedData, 1);
            $flags |= self::FLAG_COMPRESSED;
        }

        return \chr($flags & 0xff) . $serializedData;
    }

    public function unserialize(string $data = null, &$ttl = null): array
    {
        if ($data === null || $data === '') {
            return [];
        }

        $firstByte = \ord($data[0]);
        $data = \substr($data, 1);

        if ($firstByte & self::FLAG_COMPRESSED) {
            $data = \gzinflate($data);
        }

        list($ttl, $decodedData) = \unserialize($data, ['allowed_classes' => true]);

        return $decodedData;
    }
}
