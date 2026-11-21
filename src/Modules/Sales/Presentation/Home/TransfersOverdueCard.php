<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Contracts\SalesReports;

/**
 * Needs Attention's **Transfers Overdue** (dashboard.md §2 J, D6): the bank transfers overdue now, as staff's
 * Overdue badge counts them (`SalesReports::transfersOverdue`), and what they still owe - a line per store with any.
 * Offered to whoever records payments (`sales.payment.record`, §5); not shown with none overdue.
 */
final readonly class TransfersOverdueCard implements HomeCard
{
    public function __construct(
        private SalesHome $home,
        private SalesReports $reports,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::PAYMENT_RECORD);

        if ($stores === null) {
            return null;
        }

        $orders = 0;
        $rows = [];

        foreach ($stores as $store) {
            $overdue = $this->reports->transfersOverdue($store->storeId());

            if ($overdue->orders === 0) {
                continue;
            }

            $orders += $overdue->orders;
            $rows[] = new HomeRow(
                SalesHome::name($store),
                (string) trans_choice('sales::home.transfers_overdue.owed', $overdue->orders, ['count' => $overdue->orders, 'amount' => SalesHome::money($overdue->owed, $store)->money->text ?? '']),
                href: SalesHome::ordersHref($store, 'TRANSFERS_TO_CHECK'),
            );
        }

        return $orders === 0 ? null : new HomeCardData(
            [new HomeFigure('orders', $orders, tone: 'amber')],
            $rows,
            'stores',
            SalesHome::ordersHref(count($stores) > 1 ? null : $stores[0], 'TRANSFERS_TO_CHECK'),
        );
    }
}
