<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * A phone number in E.164 (+9665…), any country (owner's decision, 2026-09-18). Spaces, dashes,
 * dots and brackets are dropped, and a leading 00 becomes +.
 */
final readonly class PhoneNumber
{
    private const string E164 = '/\A\+[1-9]\d{6,14}\z/';

    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $digits = (string) preg_replace('/[\s\-.()]/', '', trim($value));

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (preg_match(self::E164, $digits) !== 1) {
            throw new InvalidAccessAttribute('phone', 'expected an international number such as +966501234567');
        }

        return new self($digits);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
