<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\GiftLevelTerms;

/**
 * What staff fill in for a gift level but its store (promotions.md §1.7), as the editor sends it: the
 * amount in the store currency's smallest unit. Checked by `GiftLevelTerms` only after the permission.
 */
final readonly class GiftLevelFields
{
    public function __construct(
        public int $amountMinor,
        public string $variantId,
        public bool $forPublic,
        public bool $forCompany,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        /** The tick box (owner, Q11 (c)): also make the size's normal sales depend on its stock, in a wired store. */
        public bool $makeStockDependent = false,
    ) {}

    public function terms(string $currencyCode): GiftLevelTerms
    {
        return GiftLevelTerms::of($this->amountMinor, $currencyCode, $this->variantId, $this->forPublic, $this->forCompany, $this->startsAt, $this->endsAt);
    }
}
