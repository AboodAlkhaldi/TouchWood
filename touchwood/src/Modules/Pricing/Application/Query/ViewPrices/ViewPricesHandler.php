<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewPrices;

use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Shared\Application\Unauthorized;

/**
 * **A size's prices** (`ViewPrices`, pricing.md §3), for whoever holds any of Pricing's three jobs in
 * that store (owner, 2026-10-08): its retail price, and every price it may be sold at - the candidates
 * each change writes (§1.6), so the screen shows exactly what an order would be priced from. A read: no
 * lock, nothing written.
 */
final readonly class ViewPricesHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput
     */
    public function handle(ViewPrices $query): SizePrices
    {
        $store = $this->access->authorize($query->storeId);
        $storeId = $store->storeId();
        // Any size there is, archived ones too: read-only is not hidden (owner, 2026-10-11).
        $variantId = $this->catalog->variant($query->variantId)->id ?? throw new InvalidPricingInput('variant');
        $retail = $this->retailPrices->forVariants($storeId, [$variantId])[$variantId] ?? null;

        return new SizePrices(
            $variantId,
            $retail?->minorUnits,
            $store->currencyCode,
            array_map(static fn (Candidate $candidate): PriceLine => new PriceLine(
                $candidate->mode->value,
                $candidate->fromQuantity,
                $candidate->amount->minorUnits,
                $candidate->kind->value,
                $candidate->alwaysWins,
                $candidate->startsAt?->format(DateTimeInterface::ATOM),
                $candidate->endsAt?->format(DateTimeInterface::ATOM),
                $candidate->sourceId,
            ), $this->candidates->forVariants($storeId, [$variantId])[$variantId] ?? []),
        );
    }
}
