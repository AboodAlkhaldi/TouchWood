<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * The company's registered name (b2b.md §1.1): one line, at most 200 characters (owner, 2026-09-27).
 * Staff check it against the commercial registration certificate; the code checks only that it is
 * a name at all.
 */
final readonly class CompanyName
{
    public const int MAX = 200;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function of(string $value): self
    {
        return new self(CompanyText::oneLine('name', $value, self::MAX));
    }

    public static function reconstitute(string $value): self
    {
        return new self($value);
    }
}
