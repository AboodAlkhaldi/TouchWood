<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\CouponLine;

/**
 * A cart's priced lines as a coupon sees them (promotions.md §1.2), in their order: each with what its
 * variant belongs to — one Catalog read for the whole cart (`lineFacts`) — its sale mode, whether it is
 * already reduced, and its total.
 */
final readonly class CartLines
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * @return list<CouponLine>
     */
    public function of(CartPricesDto $prices): array
    {
        $facts = $this->catalog->lineFacts(array_values(array_unique(array_map(static fn (LinePriceDto $line): string => $line->variantId, $prices->lines))));

        return array_map(static function (LinePriceDto $line) use ($facts): CouponLine {
            // Pricing priced it, so Catalog knows it: a variant it does not is the caller's mistake.
            $fact = $facts[strtolower(trim($line->variantId))] ?? throw new InvalidPromotionAttribute('line', "no such variant: {$line->variantId}");

            return new CouponLine(
                $fact->productId,
                $fact->brandId,
                $fact->categoryIds,
                $line->mode === SaleMode::Wholesale,
                $line->unit->compareTo($line->listUnit) < 0,
                $line->total,
            );
        }, $prices->lines);
    }
}
