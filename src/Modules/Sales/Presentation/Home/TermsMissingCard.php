<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Enums\PolicyKind;
use Modules\Sales\Application\SalesPermissions;

/**
 * Needs Attention's **No Terms and Conditions** (flags-2026-11.md S3.3, F17): "No Terms and Conditions: KSA" for
 * each store with none published - a store that cannot take orders until it has (checkout's `TermsMissing`).
 * Asked of Platform's `ShopPolicies`, which Content binds, never of Content itself. Sales' card, since the orders
 * are what stop; offered with Sales' permission (`sales.order.view`; the builder's pick). Not shown when every
 * store has its terms.
 */
final readonly class TermsMissingCard implements HomeCard
{
    public function __construct(
        private SalesHome $home,
        private ShopPolicies $policies,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::ORDER_VIEW);

        if ($stores === null) {
            return null;
        }

        $rows = [];

        foreach ($stores as $store) {
            if ($this->policies->published($store->storeId(), PolicyKind::Terms, SalesHome::locale()) === null) {
                $rows[] = new HomeRow((string) __('sales::home.terms_missing.store', ['store' => SalesHome::name($store)]), (string) __('sales::home.terms_missing.detail'));
            }
        }

        return $rows === [] ? null : new HomeCardData([new HomeFigure('stores', count($rows), tone: 'amber')], $rows);
    }
}
