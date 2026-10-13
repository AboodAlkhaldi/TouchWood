<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RebuildPriceCandidates;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The repair** (pricing.md §3, §8 #5): in every store, on or off, every size that has a retail price,
 * holds a candidate or is switched on there has its candidates written again from what they are made
 * of - the same rows each change writes - and Catalog is told its price again. One transaction per
 * store under that store's pricing lock, so no change there runs meanwhile. It changes nothing a
 * person did, so it audits nothing; it answers how many sizes were out of step.
 */
final readonly class RebuildPriceCandidatesHandler
{
    /** Reserved: the system's, or a Super Admin's. */
    public const string PERMISSION = PricingPermissions::CANDIDATES_REBUILD;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private PricingChange $change,
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
        private CandidateWriter $writer,
    ) {}

    /**
     * @return int the sizes whose candidates were not what they are made of
     *
     * @throws Unauthorized
     */
    public function handle(RebuildPriceCandidates $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $outOfStep = 0;

        foreach ($this->platform->allStores() as $store) {
            $outOfStep += $this->change->run($store->storeId(), function () use ($store): array {
                $storeId = $store->storeId();
                $variantIds = array_values(array_unique([
                    ...$this->retailPrices->pricedVariants($storeId),
                    ...$this->candidates->variantsWithCandidates($storeId),
                    ...$this->catalog->switchedOnVariantIds($storeId),
                ]));
                sort($variantIds);
                $outOfStep = 0;

                // A chunk at a time, as the writer writes them, so no statement outgrows its parameters.
                foreach (array_chunk($variantIds, CandidateWriter::CHUNK) as $chunk) {
                    $before = $this->rows($storeId, $chunk);
                    $this->writer->refresh($storeId, $chunk);
                    $after = $this->rows($storeId, $chunk);
                    $outOfStep += count(array_filter($chunk, static fn (string $id): bool => ($before[$id] ?? []) !== ($after[$id] ?? [])));
                }

                return [$outOfStep, []];
            });
        }

        return $outOfStep;
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, list<string>> variant id => its candidates, each written out, in one order
     */
    private function rows(StoreId $store, array $variantIds): array
    {
        $rows = [];

        foreach ($this->candidates->forVariants($store, $variantIds) as $variantId => $candidates) {
            $written = array_map(static fn (Candidate $candidate): string => implode('|', [
                $candidate->mode->value,
                $candidate->fromQuantity,
                $candidate->amount->minorUnits,
                $candidate->amount->currencyCode,
                $candidate->kind->value,
                $candidate->alwaysWins ? '1' : '0',
                $candidate->startsAt?->format('U.u') ?? '',
                $candidate->endsAt?->format('U.u') ?? '',
                $candidate->sourceId ?? '',
            ]), $candidates);
            sort($written);
            $rows[$variantId] = $written;
        }

        return $rows;
    }
}
