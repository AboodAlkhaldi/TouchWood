<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Domain\ValueObject\Money;

/**
 * Retail prices written (pricing.md §1.2), by staff or by a store's file (§1.8): in one transaction
 * under the store's lock, a price that would take a running or scheduled sale past the ceiling held
 * back, naming the sales and their products (owner, 2026-10-11/13); each size whose price changed
 * saved with its source and audited from and to, its candidates written again and Catalog told; a
 * size whose price is the same changes nothing.
 */
final readonly class RetailPriceWriter
{
    public function __construct(
        private PricingChange $change,
        private RetailPrices $retailPrices,
        private CandidateWriter $writer,
        private CeilingCheck $ceiling,
    ) {}

    /**
     * @param  array<string, Money>  $amounts  variant id => its price
     *
     * @throws OverCeiling
     */
    public function set(StoreDto $store, array $amounts, PriceSource $source, string $action): void
    {
        $storeId = $store->storeId();

        $this->change->run($storeId, function () use ($storeId, $store, $amounts, $source, $action): array {
            // Read again under the lock: the retried transaction runs this again (lesson 57).
            $before = $this->retailPrices->forVariants($storeId, array_keys($amounts));
            $ceiling = $this->ceiling->ceiling($storeId);
            $over = $this->ceiling->salesOver($storeId, $ceiling, $amounts);

            if ($over !== []) {
                throw new OverCeiling($ceiling, array_map(static fn (Sale $sale): string => $sale->id, $over), [], $this->ceiling->productNames($over));
            }

            $changed = [];
            $entries = [];

            foreach ($amounts as $variantId => $amount) {
                $old = $before[$variantId] ?? null;

                if ($old !== null && $old->equals($amount)) {
                    continue;
                }

                $this->retailPrices->save($storeId, $variantId, $amount, $source);
                $changed[] = $variantId;
                $entries[] = new AuditEntryDto(
                    $action,
                    'pricing.retail_price',
                    $variantId,
                    $storeId->value,
                    AuditChanges::none()->changed('amount', $old?->toDecimal($store->currencyExponent), $amount->toDecimal($store->currencyExponent)),
                );
            }

            $this->writer->refresh($storeId, $changed);

            return [null, $entries];
        });
    }
}
