<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Access\Public\Contracts\CustomerPageSection;
use Modules\Loyalty\Application\Query\CustomerPointsInStores\CustomerPointsInStores;
use Modules\Loyalty\Application\Query\CustomerPointsInStores\CustomerPointsInStoresHandler;
use Modules\Loyalty\Application\Query\CustomerPointsInStores\StorePoints;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The staff customer page's Points section (loyalty.md §4.1 L5; access.md amendment 72(a)), drawn by
 * `resources/js/sections/customer/loyalty-points.tsx`. Not drawn at all for a reader who may read
 * points in no store.
 */
final readonly class PointsCustomerSection implements CustomerPageSection
{
    public function __construct(
        private Application $app,
        private CustomerPointsInStoresHandler $handler,
        private PlatformApi $platform,
    ) {}

    public function sectionFor(string $customerId): ?array
    {
        try {
            $points = $this->handler->handle(new CustomerPointsInStores($customerId));
        } catch (Unauthorized|PointsAccountNotFound) {
            return null;
        }

        $locale = $this->app->getLocale() === 'en' ? 'en' : 'ar';

        return (new PointsSectionData(
            strtolower($customerId),
            array_map(function (StorePoints $store) use ($locale): StorePointsData {
                $known = $this->platform->store(StoreId::fromString($store->storeId));

                return new StorePointsData(
                    $store->storeCode,
                    $known?->name->in($locale) ?? $store->storeCode,
                    $store->storeIsActive,
                    $store->livePoints,
                    MoneyData::of($store->worth, $store->exponent),
                    ExpiringData::of($store->expiringNext),
                    $store->mayOpen,
                );
            }, $points->stores),
            $points->giveStoreCode,
        ))->toArray();
    }
}
