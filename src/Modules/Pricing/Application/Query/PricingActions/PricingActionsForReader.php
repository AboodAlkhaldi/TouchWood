<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PricingActions;

use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\ReaderStores;
use Modules\Pricing\Application\Prices\Words;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Public\Contracts\StoreWiring;

/**
 * **What Pricing's screens may offer** (pricing.md §4.3.1 "Who may do what"; §4.3.3): for each store where
 * the reader holds any of Pricing's three jobs - an off store only for whoever may switch stores (Q1) -
 * which jobs, whether they may set the ceiling and open products, whether the store is wired or off, its
 * ceiling, and the reasons a button is drawn disabled.
 *
 * The answer is **Pricing's, not the screen's** - a controller that asked the authorizer itself would be
 * deciding, and a test forbids it (tests/Architecture/AccessDecisionsTest) - and every handler asks again
 * anyway: offering is never allowing (handoff §19). It reads; it never locks.
 */
final readonly class PricingActionsForReader
{
    /** Catalog's job to open a product's page (catalog.md §3), named here as a value: Pricing reads none of Catalog's inside. */
    public const string PRODUCT_VIEW = 'catalog.product.view';

    public function __construct(
        private ReaderStores $stores,
        private StoreWiring $wiring,
        private CeilingCheck $ceiling,
        private Words $words,
    ) {}

    /**
     * @return list<StorePricingActions> in the stores' order
     */
    public function stores(): array
    {
        $held = $this->stores->authorize([...ReadAccess::ANY_OF_THE_THREE, PricingPermissions::CEILING_MANAGE, self::PRODUCT_VIEW]);
        $actions = [];
        $wiredStores = array_fill_keys(array_map('strtolower', $this->wiring->wiredStores()), true);

        foreach ($held as $id => ['store' => $store, 'jobs' => $jobs]) {
            // A Pricing screen is offered for any of the three; the ceiling or the products alone open none.
            if (array_intersect($jobs, ReadAccess::ANY_OF_THE_THREE) === []) {
                continue;
            }

            $wired = isset($wiredStores[$id]);
            $actions[] = new StorePricingActions(
                (string) $id,
                in_array(PricingPermissions::PRICE_EDIT, $jobs, true),
                in_array(PricingPermissions::WHOLESALE_EDIT, $jobs, true),
                in_array(PricingPermissions::CATEGORY_DISCOUNT_MANAGE, $jobs, true),
                in_array(PricingPermissions::CEILING_MANAGE, $jobs, true),
                in_array(self::PRODUCT_VIEW, $jobs, true),
                $wired,
                ! $store->isActive,
                $this->ceiling->ceiling($store->storeId()),
                $wired ? $this->words->line('admin_reasons.wired_price') : null,
            );
        }

        return $actions;
    }

    public function forStore(string $storeId): ?StorePricingActions
    {
        foreach ($this->stores() as $actions) {
            if ($actions->storeId === strtolower(trim($storeId))) {
                return $actions;
            }
        }

        return null;
    }

    public function reasons(): PricingReasons
    {
        return new PricingReasons(
            $this->words->line('admin_reasons.no_price'),
            $this->words->line('admin_reasons.not_sold_wholesale'),
            $this->words->line('admin_reasons.archived'),
            $this->words->line('admin_reasons.wired_price'),
        );
    }
}
