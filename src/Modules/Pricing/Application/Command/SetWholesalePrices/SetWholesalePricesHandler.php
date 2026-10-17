<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetWholesalePrices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SizeInput;
use Modules\Pricing\Application\PricingApiImpl;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\NotSoldWholesale;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Domain\ValueObject\Band;
use Shared\Application\Unauthorized;

/**
 * **A size's wholesale prices** (pricing.md §1.4, §3), all its bands at once, under **Edit Wholesale
 * Prices** in that store - a wired store's too: wholesale prices are ours, never the provider's
 * (handoff §12.2). The size must sell wholesale there (Catalog); the first band starts at the product's
 * wholesale minimum and costs less than the retail price (owner, 2026-10-08/10); each next band starts
 * higher and costs less; every price above 0 with no more decimals than the currency. None removes
 * them. Saved under the store's lock, audited from and to, the size's candidates written again.
 */
final readonly class SetWholesalePricesHandler
{
    public const string PERMISSION = PricingPermissions::WHOLESALE_EDIT;

    public function __construct(
        private PricingChange $change,
        private SizeInput $sizes,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private WholesaleBands $bands,
        private CandidateWriter $writer,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff|NotSoldWholesale|BandsInvalid|NoRetailPrice|PriceTooPrecise|PriceNotPositive
     */
    public function handle(SetWholesalePrices $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();
        $variantId = $this->sizes->check([$command->variantId])[$command->variantId];
        $bands = [];

        foreach ($command->bands as $from => $price) {
            // A key that looks like a number arrives as one; anything else is not a quantity - nor is one
            // above what the column keeps, the most a line may order (§2.1).
            if (! is_int($from) || $from < 1 || $from > PricingApiImpl::QUANTITY_MOST) {
                throw new InvalidPricingInput('quantity');
            }

            $bands[] = new Band($from, AmountInput::of($price, $store));
        }

        usort($bands, static fn (Band $a, Band $b): int => $a->fromQuantity <=> $b->fromQuantity);

        if ($bands !== []) {
            $listing = $this->catalog->storeVariant($storeId, $variantId);

            if ($listing === null || ! in_array(SaleMode::Wholesale, $listing->saleModes, true)) {
                throw new NotSoldWholesale;
            }

            if ($bands[0]->fromQuantity !== $listing->wholesaleMinimum) {
                throw new BandsInvalid;
            }

            for ($i = 1, $n = count($bands); $i < $n; $i++) {
                if ($bands[$i]->amount->compareTo($bands[$i - 1]->amount) >= 0) {
                    throw new BandsInvalid;
                }
            }
        }

        $this->change->run($storeId, function () use ($storeId, $store, $variantId, $bands): array {
            if ($bands !== []) {
                // Read under the lock: the retail price the first band must stay below now.
                $retail = $this->retailPrices->forVariants($storeId, [$variantId])[$variantId] ?? throw new NoRetailPrice([$variantId]);

                if ($bands[0]->amount->compareTo($retail) >= 0) {
                    throw new BandsInvalid;
                }
            }

            $before = $this->bands->forVariants($storeId, [$variantId])[$variantId] ?? [];
            $written = static fn (array $list): ?string => $list === [] ? null : implode('; ', array_map(
                static fn (Band $band): string => $band->fromQuantity.' = '.$band->amount->toDecimal($store->currencyExponent),
                $list,
            ));

            if ($written($before) === $written($bands)) {
                return [null, []];
            }

            $this->bands->replace($storeId, $variantId, $bands);
            $this->writer->refresh($storeId, [$variantId]);

            return [null, [new AuditEntryDto(
                'pricing.wholesale_prices.set',
                'pricing.wholesale_prices',
                $variantId,
                $storeId->value,
                AuditChanges::none()->changed('bands', $written($before), $written($bands)),
            )]];
        });
    }
}
