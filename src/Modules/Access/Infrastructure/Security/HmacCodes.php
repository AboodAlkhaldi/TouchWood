<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use Modules\Access\Application\Security\Codes;

final readonly class HmacCodes implements Codes
{
    /**
     * @param  string  $key  the application key
     */
    public function __construct(
        private string $key,
    ) {}

    public function generate(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    public function hash(string $owner, string $code): string
    {
        return hash_hmac('sha256', $owner.':'.$code, $this->key);
    }

    public function matches(string $owner, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($owner, trim($code)));
    }
}
