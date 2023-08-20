<?php declare(strict_types=1);

namespace Amp\Http\Server\Session;

use ParagonIE\ConstantTime\Base64UrlSafe;

final class Base64UrlSessionIdGenerator implements SessionIdGenerator
{
    /** @var non-empty-string */
    private readonly string $regex;
    /** @var positive-int */
    private readonly int $bytes;

    public function __construct(int $length = 48)
    {
        if ($length <= 0 || $length % 4 !== 0) {
            throw new \Error('Invalid length (' . $length . '), must be divisible by four');
        }

        // divisible by four to not waste chars with "=" and simplify regexp.
        /** @psalm-suppress PropertyTypeCoercion */
        $this->bytes = (int) ($length / 4 * 3);
        $this->regex = '/^[A-Za-z0-9_\-]{' . $length . '}$/';

        if ($this->bytes < 16) {
            throw new \Error('Invalid length (' . $length . '), must be at least 128 bit of entropy, i.e. an identifier length of 24 in base64url encoding');
        }
    }

    public function generate(): string
    {
        /** @var non-empty-string */
        return Base64UrlSafe::encode(\random_bytes($this->bytes));
    }

    public function validate(string $id): bool
    {
        return (bool) \preg_match($this->regex, $id);
    }
}
