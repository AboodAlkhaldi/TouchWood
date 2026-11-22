<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\InvalidCurrencyAttribute;

/**
 * ISO 4217.
 */
final readonly class CurrencyCode
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Z]{3}\z/', $value) !== 1) {
            throw new InvalidCurrencyAttribute('code', 'expected a three-letter ISO 4217 code');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
