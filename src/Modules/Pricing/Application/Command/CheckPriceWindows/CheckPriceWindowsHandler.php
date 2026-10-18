<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\CheckPriceWindows;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\Sales;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * **The daily safety check** (pricing.md §1.10, §3; owner, 2026-10-09): every size of a sale that started
 * or ended in the last day has its candidates written again and Catalog told its price - what the
 * timed tasks did, done again in case one was lost. The last 25 hours, so a run an hour late misses
 * nothing. One transaction per store under its pricing lock; audits nothing.
 */
final readonly class CheckPriceWindowsHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = PricingPermissions::WINDOWS_CHECK;

    public function __construct(
        private Authorizer $authorizer,
        private PricingChange $change,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
        private CandidateWriter $writer,
    ) {}

    /**
     * @return int the sizes pushed
     *
     * @throws Unauthorized
     */
    public function handle(CheckPriceWindows $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $now = CarbonImmutable::now();
        $sizes = [];

        [$from, $to] = [$now->subHours(25)->toDateTimeImmutable(), $now->toDateTimeImmutable()];

        foreach ($this->sales->windowsBetween($from, $to) as $sale) {
            $sizes[$sale->storeId] = [...$sizes[$sale->storeId] ?? [], ...$sale->variantIds];
        }

        foreach ($this->discounts->windowsBetween($from, $to) as $discount) {
            $reached = array_keys($this->coverage->pricedSizes(StoreId::fromString($discount->storeId), [$discount]));
            $sizes[$discount->storeId] = [...$sizes[$discount->storeId] ?? [], ...$reached];
        }

        $pushed = 0;
        $failed = null;

        foreach ($sizes as $storeId => $variantIds) {
            $store = StoreId::fromString((string) $storeId);
            $variantIds = array_values(array_unique($variantIds));
            sort($variantIds);

            // One store failing never costs the others their push; the first failure is raised once
            // every store was tried, so the run still shows as failed.
            try {
                $this->change->run($store, function () use ($store, $variantIds): array {
                    // A discount's end its task missed is the store's last, maybe: a wired store is told.
                    $this->writer->refresh($store, $variantIds, discountsChanged: true);

                    return [null, []];
                });
                $pushed += count($variantIds);
            } catch (Throwable $error) {
                $failed ??= $error;
            }
        }

        if ($failed !== null) {
            throw $failed;
        }

        return $pushed;
    }
}
