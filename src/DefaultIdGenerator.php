<?php

namespace Amp\Http\Server\Session;

use ParagonIE\ConstantTime\Base64UrlSafe;

final class DefaultIdGenerator implements IdGenerator
{
    private const ID_REGEXP = '/^[A-Za-z0-9_\-]{48}$/';
    private const ID_BYTES = 36; // divisible by three to not waste chars with "=" and simplify regexp.

    public function generate(): string
    {
        return Base64UrlSafe::encode(\random_bytes(self::ID_BYTES));
    }

    public function validate(string $id): bool
    {
        return (bool) \preg_match(self::ID_REGEXP, $id);
    }
}
