<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListGiftLevels;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Promotions\Application\Query\GiftLevelRow;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Domain\Model\GiftLevel;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * A store's gift levels (promotions.md §1.7, §3): every one — a store has few — highest amount first,
 * each gift named through Catalog in two bulk reads (the variants, then their products).
 */
final readonly class ListGiftLevelsHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private GiftLevels $levels,
        private CatalogApi $catalog,
    ) {}

    /**
     * @return list<GiftLevelRow>
     *
     * @throws Unauthorized
     */
    public function handle(ListGiftLevels $query): array
    {
        [$store, $permission] = $this->reader->store($query->storeId);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);

        $levels = $this->levels->ofStore($store);
        $variants = $levels === [] ? [] : $this->catalog->variants(array_values(array_unique(array_map(static fn (GiftLevel $level): string => $level->variantId, $levels))));
        $productIds = array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, $variants)));
        $products = $productIds === [] ? [] : $this->catalog->products($productIds);
        $now = CarbonImmutable::now();

        return array_map(static function (GiftLevel $level) use ($variants, $products, $now): GiftLevelRow {
            $variant = $variants[$level->variantId] ?? null;
            $product = $variant === null ? null : ($products[$variant->productId] ?? null);

            return new GiftLevelRow(
                $level->id,
                Money::of($level->amountMinor, $level->currencyCode),
                $level->variantId,
                $variant?->code,
                $product?->nameAr,
                $product?->nameEn,
                $variant === null || $variant->isArchived,
                $level->forPublic,
                $level->forCompany,
                $level->startsAt,
                $level->endsAt,
                $level->active,
                $level->active && ($level->startsAt === null || $level->startsAt <= $now) && ($level->endsAt === null || $level->endsAt > $now),
            );
        }, $levels);
    }
}
