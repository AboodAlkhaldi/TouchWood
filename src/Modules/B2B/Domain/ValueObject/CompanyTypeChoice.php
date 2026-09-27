<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * What kind of company it is: **one of the types staff manage, or "Other" in the company's own
 * words** — exactly one of the two (owner, 2026-09-27, b2b.md amendment 2). "Other" is not a row,
 * so there is nothing staff could deactivate that would take it off the form.
 */
final readonly class CompanyTypeChoice
{
    /** The company's own words for "Other". */
    public const int OTHER_MAX = 100;

    private function __construct(
        public ?string $typeId,
        public ?string $other,
    ) {}

    /**
     * One of the listed types. Whether it exists and is still offered is the application's question
     * when it is sent, not this value's.
     */
    public static function listed(string $typeId): self
    {
        return new self(strtolower($typeId), null);
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function other(string $words): self
    {
        return new self(null, CompanyText::oneLine('company_type_other', $words, self::OTHER_MAX));
    }

    /**
     * Read back from a row, which holds exactly one of the two.
     */
    public static function reconstitute(?string $typeId, ?string $other): self
    {
        return $typeId !== null ? new self($typeId, null) : new self(null, (string) $other);
    }

    public function isOther(): bool
    {
        return $this->typeId === null;
    }

    public function equals(self $other): bool
    {
        return $this->typeId === $other->typeId && $this->other === $other->other;
    }
}
