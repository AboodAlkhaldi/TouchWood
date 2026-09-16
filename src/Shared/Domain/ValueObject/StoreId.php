<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The identity of a store. A ULID, kept in lowercase to match the ids Eloquent generates.
 */
final readonly class StoreId
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}\z/i', $value) !== 1) {
            throw new InvalidArgumentException("Invalid store id \"{$value}\": expected a ULID.");
        }

        return new self(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
