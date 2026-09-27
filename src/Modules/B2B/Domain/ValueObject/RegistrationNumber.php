<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * A Commercial Registration number or a tax number (b2b.md §1.1, handoff §8.1).
 *
 * **Checked loosely** (owner, 2026-09-27): one line, at most 50 characters of letters, digits,
 * spaces and dashes, in any script. Three countries write these three ways, and a format per
 * country is three rule sets to keep right; staff check the number against the certificate, which
 * is what they trust anyway.
 */
final readonly class RegistrationNumber
{
    public const int MAX = 50;

    private function __construct(public string $value) {}

    /**
     * @param  'cr_number'|'tax_number'  $attribute
     *
     * @throws InvalidCompanyAttribute
     */
    public static function of(string $attribute, string $value): self
    {
        $number = CompanyText::oneLine($attribute, $value, self::MAX);

        if (preg_match('/\A[\p{L}\p{N} \-]+\z/u', $number) !== 1) {
            throw new InvalidCompanyAttribute($attribute, 'letters, digits, spaces and dashes only');
        }

        return new self($number);
    }

    public static function reconstitute(string $value): self
    {
        return new self($value);
    }
}
