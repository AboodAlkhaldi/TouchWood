<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Shared\Domain\Text\LatinDigits;

/**
 * The registered address (b2b.md §1.1, §5.1): **picked from the account's saved addresses** and
 * **kept as a copy** (owner, 2026-09-30, amendment 16(f)) — the address as its store's format
 * writes it, and which saved address it was picked from. Editing or deleting that saved address
 * later changes neither the company nor an application: an application is a snapshot, and the
 * company's address changes only when another is picked.
 *
 * At most 6,000 characters, the column's bound (amendment 17(e)): Access's own limits keep a
 * formatted address within it unless its template repeats a field, and a longer one is refused as
 * too long. Line breaks kept, no map pin.
 *
 * Addresses written before amendment 16 — typed, one block of text — and the placeholder
 * anonymizing leaves have no saved address behind them.
 */
final readonly class CompanyAddress
{
    public const int MAX = 6000;

    private function __construct(
        public string $value,
        /** The saved address it was picked from; null for one that was typed, or anonymized. */
        public ?string $addressId,
    ) {}

    /**
     * A saved address of the account, as its store's format writes it. Which account it belongs to
     * and whether its format still accepts it are the use case's to check, with Access.
     *
     * @throws InvalidCompanyAttribute
     */
    public static function saved(string $addressId, string $formatted): self
    {
        // Digits in Latin (amendment 29): Access saves them so since 2026-10-06, and an older saved
        // address is turned here as it is copied.
        return new self(CompanyText::lines('address', LatinDigits::of($formatted), self::MAX), strtolower($addressId));
    }

    /**
     * Text alone, with no saved address behind it: the placeholder anonymizing leaves.
     *
     * @throws InvalidCompanyAttribute
     */
    public static function of(string $value): self
    {
        return new self(CompanyText::lines('address', LatinDigits::of($value), self::MAX), null);
    }

    public static function reconstitute(string $value, ?string $addressId = null): self
    {
        return new self($value, $addressId);
    }

    /**
     * The same text picked from the same saved address. Another saved address that happens to read
     * the same is still another pick: the page shows which one is picked by its id.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value && $this->addressId === $other->addressId;
    }
}
