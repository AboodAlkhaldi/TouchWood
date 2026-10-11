<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetRetailPrice;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\StoreWiring;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Exception\ProviderOwnsPrice;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **Setting retail prices** in a store (pricing.md §1.2, §3), one size or several at once, under
 * **Edit Prices and Sales** in that store. Each is above 0 and has no more decimals than the store's
 * currency; a wired store's retail prices are the provider's, read-only here (§1.1 rule 9). Each size
 * whose price changed is audited from and to, its candidates are written again and Catalog is told -
 * all in one transaction under the store's lock; a size whose price is the same changes nothing.
 */
final readonly class SetRetailPriceHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private StoreWiring $wiring,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private CandidateWriter $writer,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff|ProviderOwnsPrice|PriceTooPrecise|PriceNotPositive
     */
    public function handle(SetRetailPrice $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if ($this->wiring->isWired($storeId)) {
            throw new ProviderOwnsPrice;
        }

        /** @var array<string, Money> $amounts */
        $amounts = [];

        foreach ($command->prices as $variantId => $amount) {
            // The variant's own id, however the caller wrote it (lesson 65). An archived size is not on
            // sale (Catalog), so it takes no new price; the one it has is kept (pricing.md §6.2).
            $variant = $this->catalog->variant((string) $variantId);

            if ($variant === null || $variant->isArchived) {
                throw new InvalidPricingInput('variant');
            }

            $amounts[$variant->id] = AmountInput::of($amount, $store);
        }

        if ($amounts === []) {
            return;
        }

        $this->change->run($storeId, function () use ($storeId, $store, $amounts): array {
            // Read again under the lock: the retried transaction runs this again (lesson 57).
            $before = $this->retailPrices->forVariants($storeId, array_keys($amounts));
            $changed = [];
            $entries = [];

            foreach ($amounts as $variantId => $amount) {
                $old = $before[$variantId] ?? null;

                if ($old !== null && $old->equals($amount)) {
                    continue;
                }

                $this->retailPrices->save($storeId, $variantId, $amount, PriceSource::STAFF);
                $changed[] = $variantId;
                $entries[] = new AuditEntryDto(
                    'pricing.retail_price.set',
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
