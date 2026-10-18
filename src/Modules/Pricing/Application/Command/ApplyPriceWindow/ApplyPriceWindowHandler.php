<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\ApplyPriceWindow;

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

/**
 * **The timed task** (pricing.md §1.10, §3; owner, 2026-10-09): at a sale's or a category discount's
 * start or end, its sizes' candidates written again and Catalog told the price a card shows now. Under the store's pricing lock;
 * a sale removed meanwhile, or whose start and end are no longer this moment, has nothing to do here -
 * its change pushed already, and queued its own tasks. Changes nothing a person did, so audits nothing.
 */
final readonly class ApplyPriceWindowHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = PricingPermissions::WINDOWS_APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private PricingChange $change,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
        private CandidateWriter $writer,
    ) {}

    /**
     * @return bool whether the sizes were pushed
     *
     * @throws Unauthorized
     */
    public function handle(ApplyPriceWindow $command): bool
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $store = StoreId::fromString($command->storeId);
        $moment = CarbonImmutable::parse($command->moment)->toDateTimeImmutable();

        return $this->change->run($store, function () use ($store, $command, $moment): array {
            // A sale's id, or a category discount's: one id names one of them.
            $sale = $this->sales->find($command->saleId);
            $discount = $sale === null ? $this->discounts->find($command->saleId) : null;
            $dated = $sale ?? $discount;

            if ($dated === null || $dated->storeId !== $store->value || ! $dated->startsOrEndsAt($moment)) {
                return [false, []];
            }

            $this->writer->refresh($store, $sale !== null ? $sale->variantIds : array_keys($this->coverage->pricedSizes($store, $discount === null ? [] : [$discount])), discountsChanged: $sale === null);

            return [true, []];
        });
    }
}
