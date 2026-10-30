<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListPriceGroups;

use Collator;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Query\PlaceRow;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Shared\Application\Unauthorized;

/**
 * The carrier's groups by amount, cheapest first, each one's places sorted by name in the page's
 * language. Places and the places above them are read from Platform in two reads.
 */
final readonly class ListPriceGroupsHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_VIEW;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
        private PriceGroupRepository $groups,
        private PlatformApi $platform,
    ) {}

    /**
     * @return CarrierPrices the groups, and the version a save sends back
     *
     * @throws CarrierNotFound|InvalidShippingInput|Unauthorized
     */
    public function handle(ListPriceGroups $query): CarrierPrices
    {
        $store = $this->change->authorize(self::PERMISSION, $query->storeId);
        $carrier = $this->carriers->byId($store->id, $query->carrierId) ?? throw new CarrierNotFound($query->carrierId);
        $groups = $this->groups->ofCarrier($carrier->id);
        $ids = [];

        foreach ($groups as $group) {
            array_push($ids, ...$group->placeIds());
        }

        $places = $ids === [] ? [] : $this->platform->place($ids);
        $parents = $this->platform->place(PlaceRow::parentIds(array_values($places)));
        $collator = new Collator($query->locale === 'ar' ? 'ar' : 'en');
        $rows = [];

        foreach ($groups as $group) {
            $own = [];

            foreach ($group->placeIds() as $id) {
                if (isset($places[$id])) {
                    $own[] = PlaceRow::of($places[$id], $parents);
                }
            }

            usort($own, static fn (PlaceRow $a, PlaceRow $b): int => (int) $collator->compare($a->name->in($query->locale), $b->name->in($query->locale)));
            $rows[] = new PriceGroupRow($group->id, $group->amountMinor(), $own);
        }

        return new CarrierPrices(PriceGroup::versionOf($groups), $rows);
    }
}
