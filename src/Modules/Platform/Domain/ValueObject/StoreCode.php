<?php

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\InvalidStoreAttribute;

/**
 * The store's URL segment: brand.com/{code}.
 */
final readonly class StoreCode
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z]{2,8}$/', $value) !== 1) {
            throw new InvalidStoreAttribute('code', 'expected 2 to 8 lowercase letters');
        }

        return new self($value);
    }
}
