<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * An email address, kept as typed (trimmed) and compared ignoring case: the database's unique
 * index is on lower(email) (spec §5.3).
 */
final readonly class EmailAddress
{
    private const int MAX_LENGTH = 254;

    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidAccessAttribute('email', 'not a valid email address');
        }

        return new self($value);
    }

    public function sameAs(self $other): bool
    {
        return mb_strtolower($this->value) === mb_strtolower($other->value);
    }
}
