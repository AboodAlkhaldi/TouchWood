<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * One gift level of a store (promotions.md §1.7, §4): its amount, its gift — the variant, its code and
 * its product's names (null when Catalog no longer has it; `variantArchived` is then true too) — its
 * buyers and dates, its switch, and whether it gives its gift now (switched on, within its dates).
 */
final readonly class GiftLevelRow
{
    public function __construct(
        public string $id,
        public Money $amount,
        public string $variantId,
        public ?string $variantCode,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public bool $variantArchived,
        public bool $forPublic,
        public bool $forCompany,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $active,
        public bool $inEffect,
    ) {}
}
