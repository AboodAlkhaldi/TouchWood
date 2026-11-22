<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncActions;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\Report\SyncWords;
use Modules\Sync\Application\SyncPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * **What Sync's screens may offer** (sync.md §4.1.3 `SyncActionsForReader`; pricing.md §4.3.1 "Who may do
 * what"): for each store where the reader holds any of Sync's jobs - or may wire stores (a Super Admin,
 * every store) - and, off, only for whoever may switch stores (Q1): what they may do there, whether it is
 * wired, whether a Sync Now read waits, and the reasons a control is drawn disabled.
 *
 * The answer is **Sync's, not the screen's** - a controller that asked the authorizer itself would be
 * deciding, and a test forbids it (tests/Architecture/AccessDecisionsTest) - and every handler asks again
 * anyway: offering is never allowing (handoff §19). It reads; it never locks. A fixed few queries for every
 * store at once.
 */
final readonly class SyncActionsForReader
{
    /** Catalog's job to open a product's page (catalog.md §3), named here as a value: Sync reads none of Catalog's inside. */
    public const string PRODUCT_VIEW = 'catalog.product.view';

    /**
     * Pricing's three jobs, any of which opens the Offers and Category Discounts pages (pricing.md §4.3.2),
     * named here as values for the same reason.
     *
     * @var list<string>
     */
    public const array OFFERS_VIEW = ['pricing.price.edit', 'pricing.wholesale.edit', 'pricing.category_discount.manage'];

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private SyncLog $log,
        private SyncWords $words,
    ) {}

    /**
     * @return list<StoreSyncActions> in the stores' order
     */
    public function stores(): array
    {
        return $this->answer(null);
    }

    public function forStore(string $storeId): ?StoreSyncActions
    {
        return $this->answer(strtolower(trim($storeId)))[0] ?? null;
    }

    public function reasons(): SyncReasons
    {
        return new SyncReasons(
            $this->words->line('admin_report.reasons.size_gone'),
            $this->words->line('admin_report.reasons.read_failed_stays'),
        );
    }

    /**
     * @return list<StoreSyncActions>
     */
    private function answer(?string $only): array
    {
        $held = [];

        foreach ([SyncPermissions::REPORT_VIEW, SyncPermissions::READ_RUN, SyncPermissions::CONNECTION_MANAGE, self::PRODUCT_VIEW, ...self::OFFERS_VIEW] as $permission) {
            $stores = $this->authorizer->storesWith($permission);
            $held[$permission] = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);
        }

        $holds = static fn (string $permission, string $store): bool => $held[$permission] === null || in_array($store, $held[$permission], true);
        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];
        // The wiring and the waiting Sync Now reads of every store, in one query.
        $glance = $this->log->glance();
        $wired = array_fill_keys(array_map('strtolower', $glance['wired']), true);
        $waiting = array_fill_keys(array_map('strtolower', $glance['nowWaiting']), true);
        $refusal = $this->words->line('admin_report.reasons.now_waiting');
        $actions = [];

        foreach ($this->platform->allStores() as $store) {
            $id = strtolower($store->id);

            if (($only !== null && $id !== $only) || (! $store->isActive && ! $mayOff)) {
                continue;
            }

            $view = $holds(SyncPermissions::REPORT_VIEW, $id);
            $run = $holds(SyncPermissions::READ_RUN, $id);
            $wire = $holds(SyncPermissions::CONNECTION_MANAGE, $id);

            // A Sync screen is offered for one of Sync's jobs; the products or the offers alone open none.
            if (! $view && ! $run && ! $wire) {
                continue;
            }

            $nowWaiting = isset($waiting[$id]);
            $actions[] = new StoreSyncActions(
                $id,
                $view,
                $run,
                $wire,
                $holds(self::PRODUCT_VIEW, $id),
                array_filter(self::OFFERS_VIEW, static fn (string $permission): bool => $holds($permission, $id)) !== [],
                isset($wired[$id]),
                ! $store->isActive,
                $nowWaiting,
                $nowWaiting ? $refusal : null,
            );
        }

        return $actions;
    }
}
