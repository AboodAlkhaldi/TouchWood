<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * The registered address: **one block of text** (owner, 2026-09-27, b2b.md amendment 2) — at most
 * 500 characters, line breaks kept, no map pin. Staff read it as it was typed.
 *
 * Nothing downstream reads it: invoices come from the external accounting system (handoff §12.6),
 * and deliveries go to the person's own addresses, which keep Access's structured form.
 */
final readonly class CompanyAddress
{
    public const int MAX = 500;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function of(string $value): self
    {
        return new self(CompanyText::lines('address', $value, self::MAX));
    }

    public static function reconstitute(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
