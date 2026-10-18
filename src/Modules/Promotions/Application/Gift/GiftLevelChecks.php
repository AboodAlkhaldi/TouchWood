<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Gift;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\GiftLevelTerms;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Shared\Domain\ValueObject\StoreId;

/**
 * What saving a gift level checks (promotions.md §1.2, §1.7): its gift **a variant that is not
 * archived, of a ready product** — through Catalog's public reads — and **no other active level of the
 * store with the same amount for overlapping buyers and dates** (one amount, one gift), read under the
 * store's lock.
 */
final readonly class GiftLevelChecks
{
    public function __construct(
        private CatalogApi $catalog,
        private GiftLevels $levels,
    ) {}

    /**
     * @throws InvalidPromotionAttribute
     */
    public function variant(string $variantId): void
    {
        $variant = $this->catalog->variant($variantId);

        if ($variant === null || $variant->isArchived || $this->catalog->product($variant->productId)?->stage !== ProductStage::Ready) {
            throw new InvalidPromotionAttribute('gift_variant', "{$variantId} is not a variant of a ready product");
        }
    }

    /**
     * @throws GiftLevelClash
     */
    public function noClash(StoreId $store, GiftLevelTerms $terms, ?string $exceptId): void
    {
        foreach ($this->levels->activeWithAmount($store, $terms->amountMinor, $exceptId) as $other) {
            if ($terms->clashesWith($other)) {
                throw new GiftLevelClash;
            }
        }
    }
}
