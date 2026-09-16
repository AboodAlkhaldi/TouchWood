<?php

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\InvalidStoreAttribute;

/**
 * ISO 3166-1 alpha-2.
 */
final readonly class CountryCode
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            throw new InvalidStoreAttribute('country', 'expected a two-letter ISO 3166-1 code');
        }

        return new self($value);
    }
}
