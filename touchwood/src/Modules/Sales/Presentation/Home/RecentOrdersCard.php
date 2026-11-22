<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeCell;
use Modules\Platform\Public\Dto\HomeColumn;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeStorePanel;
use Modules\Platform\Public\Dto\HomeTable;
use Modules\Platform\Public\Dto\HomeTableRow;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\Query\Home\RecentOrder;
use Modules\Sales\Application\Query\Home\SalesHomeReads;
use Modules\Sales\Application\SalesPermissions;

/**
 * Home's **Recent orders** (dashboard.md §2 G, D4): the last 8 placed - whatever the period - with the first line's
 * photo and name ("+2 more" when there are more), the customer as they ordered, the pieces, the way to pay, the
 * status badge and the total; **View All Orders**. One store at a time behind store tabs for All Stores, as the
 * charts (the builder's pick: each total is in its own store's currency).
 */
final readonly class RecentOrdersCard implements HomeCard
{
    /** How many are listed (D4). */
    public const int LISTED = 8;

    /** The Orders list's own badge colours for a status (resources/js/components/sales/admin.tsx). */
    private const array TONES = ['PENDING_APPROVAL' => 'amber-subtle', 'APPROVED' => 'blue-subtle', 'COMPLETED' => 'green-subtle', 'REJECTED' => 'gray-subtle', 'CANCELLED' => 'gray-subtle', 'UNDELIVERED' => 'red-subtle'];

    public function __construct(
        private SalesHome $home,
        private SalesHomeReads $reads,
        private CatalogApi $catalog,
        private PlatformApi $platform,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::ORDER_VIEW);

        if ($stores === null || $stores === []) {
            return null;
        }

        $panels = array_map(fn (StoreDto $store): HomeStorePanel => new HomeStorePanel(
            strtolower($store->code),
            SalesHome::name($store),
            table: $this->table($store, $this->reads->recentOrders($store->storeId(), self::LISTED)),
        ), $stores);

        return new HomeCardData([], href: SalesHome::ordersHref(count($stores) > 1 ? null : $stores[0], 'ALL'), size: HomeSize::Full, panels: $panels);
    }

    /** @param  list<RecentOrder>  $orders */
    private function table(StoreDto $store, array $orders): HomeTable
    {
        $locale = SalesHome::locale();
        $products = array_values(array_unique(array_filter(array_map(static fn (RecentOrder $order): ?string => $order->productId, $orders))));
        $cards = $products === [] ? [] : $this->catalog->productCards($store->storeId(), $locale, $products);
        $photos = array_values(array_filter(array_map(static fn ($card): ?string => $card->photoMediaId, $cards)));
        $urls = $photos === [] ? [] : $this->platform->mediaUrlsOf($photos);
        $rows = [];

        foreach ($orders as $order) {
            $card = $order->productId === null ? null : ($cards[strtolower($order->productId)] ?? null);
            $photo = $card?->photoMediaId === null ? null : ($urls[strtolower($card->photoMediaId)] ?? null);
            $more = $order->moreLines > 0 ? (string) __('sales::home.recent_orders.more', ['count' => $order->moreLines]) : null;

            $rows[] = new HomeTableRow(
                $locale === 'en' && $order->nameEn !== '' ? $order->nameEn : $order->nameAr,
                [
                    new HomeCell(text: $order->customerName),
                    new HomeCell(HomeValue::count($order->pieces)),
                    new HomeCell(text: (string) __("sales::admin_orders.way.{$order->wayToPay}")),
                    new HomeCell(text: (string) __("sales::admin_orders.status.{$order->status}"), badge: self::TONES[$order->status] ?? 'gray-subtle'),
                    new HomeCell(SalesHome::money($order->total, $store)),
                ],
                implode(' · ', array_filter([$order->number, $more])),
                SalesHome::thumb($photo),
                route('sales.admin.orders.show', ['order' => $order->orderId], absolute: false),
            );
        }

        return new HomeTable([
            new HomeColumn((string) __('sales::home.recent_orders.product')),
            new HomeColumn((string) __('sales::home.recent_orders.customer')),
            new HomeColumn((string) __('sales::home.recent_orders.pieces'), end: true),
            new HomeColumn((string) __('sales::home.recent_orders.way')),
            new HomeColumn((string) __('sales::home.recent_orders.status')),
            new HomeColumn((string) __('sales::home.recent_orders.total'), end: true),
        ], $rows, (string) __('sales::home.recent_orders.empty'));
    }
}
