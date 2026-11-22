<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * A company type's or a document type's name, in Arabic and in English — both required, as every
 * name staff manage is in this system (b2b.md §1.3).
 *
 * One line of real text, at most 100 characters in each language (owner, 2026-09-27): it is a
 * choice in a dropdown. Two types may not share a name in either language, ignoring case; that is a
 * question about every other row, so the repository answers it, not this class.
 */
final readonly class TypeName
{
    public const int MAX = 100;

    private function __construct(
        public string $ar,
        public string $en,
    ) {}

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function of(string $ar, string $en): self
    {
        return new self(CompanyText::oneLine('name_ar', $ar, self::MAX), CompanyText::oneLine('name_en', $en, self::MAX));
    }

    /**
     * A name read back from the database, which already holds only what of() accepted.
     */
    public static function reconstitute(string $ar, string $en): self
    {
        return new self($ar, $en);
    }

    public function equals(self $other): bool
    {
        return $this->ar === $other->ar && $this->en === $other->en;
    }
}
