<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\SearchEditSizes;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Public\Enums\OrderAudience;
use Shared\Application\Unauthorized;

/**
 * **The order edit's size search** (sales.md §3.2; the screens' B7): the store's sizes — switched on there,
 * not archived — by a product's name or a size's code, 20 at most, less those the order already has (staff
 * change those lines' quantity), and **less those the edit would refuse** (§1.7, checkout's rules): not
 * orderable now, not available now, not open to the order's account, no stock record or not orderable in
 * Inventory. Each with today's prices — wholesale only on a company's order — and the stock available when
 * its stock counts. For staff who edit that store's orders.
 */
final readonly class SearchEditSizesHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_EDIT;

    public const int MOST = 20;

    public function __construct(
        private OrderAccess $access,
        private StaffReads $reads,
        private OwnReads $lines,
        private StaffReader $reader,
        private CatalogApi $catalog,
        private PricingApi $pricing,
        private InventoryApi $inventory,
    ) {}

    /**
     * @return list<EditSizeRow>
     *
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(SearchEditSizes $query): array
    {
        [$orderId, $store] = $this->access->authorize(self::PERMISSION, $query->orderId);
        $order = $this->reads->order($orderId) ?? throw new OrderNotFound;
        $text = trim($query->q);
        $has = array_values(array_unique(array_map(static fn (array $line): string => (string) $line['variant_id'], $this->lines->lines($orderId))));
        $found = $this->catalog->searchVariants($store, $text === '' ? null : mb_substr($text, 0, 100), except: $has, limit: self::MOST, notArchived: true, withTotal: false)->rows;

        if ($found === []) {
            return [];
        }

        $ids = array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found);
        $listed = $this->catalog->storeVariants($store, $ids);
        $company = $order['audience'] === OrderAudience::Company->value;
        $open = array_flip($this->catalog->productsOpenTo($store, $company ? ShopperKind::Company : ShopperKind::Individual, array_values(array_unique(array_map(static fn (FoundVariantDto $row): string => $row->productId, $found)))));
        $stock = $this->inventory->stock($store, $ids);
        // What the edit would refuse left out (OrderEditing: checkout's rules on an added line).
        $found = array_values(array_filter($found, static function (FoundVariantDto $row) use ($listed, $open, $stock): bool {
            $variant = $listed[$row->variantId] ?? null;
            $held = $stock[$row->variantId] ?? null;

            return $variant !== null && $variant->isActive && $variant->orderable && ! $variant->notAvailableNow && isset($open[$row->productId])
                && $held !== null && $held->orderable;
        }));

        if ($found === []) {
            return [];
        }

        $ids = array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found);
        $asked = [];

        foreach ($ids as $id) {
            $variant = $listed[$id] ?? null;

            if ($variant !== null && in_array(SaleMode::Retail, $variant->saleModes, true)) {
                $asked[] = new CartLineDto($id, SaleMode::Retail, 1);
            }

            if ($company && $variant !== null && in_array(SaleMode::Wholesale, $variant->saleModes, true)) {
                $asked[] = new CartLineDto($id, SaleMode::Wholesale, max(1, $variant->wholesaleMinimum ?? 1));
            }
        }

        $prices = [];

        foreach ($asked === [] ? [] : $this->pricing->prices($store, $asked)->lines as $line) {
            $prices[$line->variantId.'|'.$line->mode->value] = $line;
        }

        $bands = $company ? $this->pricing->wholesaleBands($store, $ids) : [];
        $exponent = $this->reader->exponent((string) $order['currency_code']);
        $price = static fn (?LinePriceDto $line): ?array => $line === null ? null : ['now' => $line->unit, 'before' => $line->unit->minorUnits < $line->listUnit->minorUnits ? $line->listUnit : null];

        return array_map(static function (FoundVariantDto $row) use ($listed, $prices, $bands, $stock, $exponent, $price): EditSizeRow {
            $retail = $price($prices[$row->variantId.'|RETAIL'] ?? null);
            $wholesale = $price($prices[$row->variantId.'|WHOLESALE'] ?? null);

            return new EditSizeRow(
                $row->variantId, $row->productId, $row->productNameAr, $row->productNameEn ?? $row->productNameAr, $row->code,
                array_map(static fn ($value): array => [
                    'attributeAr' => $value->attribute->ar, 'attributeEn' => $value->attribute->en, 'valueAr' => $value->value->ar, 'valueEn' => $value->value->en,
                ], $row->values),
                $row->photoMediaId, $retail,
                $wholesale === null ? null : [...$wholesale, 'minimum' => max(1, $listed[$row->variantId]->wholesaleMinimum ?? 1), 'bands' => $bands[$row->variantId] ?? []],
                ($stock[$row->variantId]->countsOnStock ?? false) ? $stock[$row->variantId]->available : null, $exponent,
            );
        }, $found);
    }
}
