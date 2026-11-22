<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\CreateGiftLevel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\Gift\GiftLevelChecks;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Creates a store's gift level (promotions.md §1.7, §3): its amount in the store's currency, its gift a
 * variant of a ready product, refused when an active level of the store already gives a gift for that
 * amount to the same buyers on the same dates; audited.
 */
final readonly class CreateGiftLevelHandler
{
    public const string PERMISSION = PromotionsPermissions::GIFT_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private GiftLevels $levels,
        private GiftLevelChecks $checks,
        private InventoryApi $inventory,
    ) {}

    /**
     * @throws Unauthorized|InvalidPromotionAttribute|GiftLevelClash
     */
    public function handle(CreateGiftLevel $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        if ($command->fields->makeStockDependent) {
            $this->change->authorize(InventoryPermissions::STOCK_MANAGE, $store->id);
        }

        $terms = $command->fields->terms($store->currencyCode);
        $this->checks->variant($terms->variantId);
        $storeId = StoreId::fromString($store->id);

        return $this->change->run($store, function () use ($storeId, $terms, $command): array {
            if ($command->active) {
                $this->checks->noClash($storeId, $terms, null);
            }

            $levelId = strtolower((string) Str::ulid());
            $this->levels->create($levelId, $storeId, $terms, $command->active, CarbonImmutable::now());

            $this->stockDependent($storeId, $terms->variantId, $command->fields->makeStockDependent);

            return [$levelId, array_filter([CouponAudit::entry('created', $levelId, $storeId->value, [], ['active' => $command->active, ...$terms->snapshot()], 'gift_level')])];
        });
    }

    /**
     * The tick box (promotions.md M4; owner, Q11 (c)): the size's normal sales made to depend on its
     * stock — in the level's own save, so no level is saved without it; called LAST, after every write
     * of Promotions', no Promotions lock after it (inventory.md §2.1: Inventory's and Catalog's locks are
     * held until the save commits). Nothing happens in a store with no provider, nor for a size on already.
     */
    private function stockDependent(StoreId $store, string $variantId, bool $ticked): void
    {
        if ($ticked) {
            $this->inventory->makeStockDependent($store, [$variantId]);
        }
    }
}
