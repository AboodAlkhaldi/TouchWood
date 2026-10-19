<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use DateTimeImmutable;

/** A store's gift level as it is now (promotions.md §1.7, §5). Active within its dates while switched on (§4). */
final readonly class GiftLevel
{
    public function __construct(
        public string $id,
        public string $storeId,
        public int $amountMinor,
        public string $currencyCode,
        public string $variantId,
        public bool $forPublic,
        public bool $forCompany,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $active,
    ) {}

    /** Its terms, as stored — for comparing and for the clash check. */
    public function terms(): GiftLevelTerms
    {
        return GiftLevelTerms::stored($this);
    }
}
