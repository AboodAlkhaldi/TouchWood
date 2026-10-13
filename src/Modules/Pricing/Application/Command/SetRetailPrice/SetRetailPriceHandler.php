<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetRetailPrice;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SizeInput;
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
 * currency; an archived size is read-only until restored (owner, 2026-10-11); a wired store's retail
 * prices are the provider's, read-only here (§1.1 rule 9). Each size whose price changed is audited
 * from and to, its candidates are written again and Catalog is told - all in one transaction under
 * the store's lock; a size whose price is the same changes nothing.
 */
final readonly class SetRetailPriceHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private StoreWiring $wiring,
        private SizeInput $sizes,
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

        // An array key that looks like a number arrives as one.
        $own = $this->sizes->check(array_map('strval', array_keys($command->prices)));

        /** @var array<string, Money> $amounts */
        $amounts = [];

        foreach ($command->prices as $given => $amount) {
            $variantId = $own[(string) $given];
            $money = AmountInput::of($amount, $store);

            // One size written two ways with two prices: which was meant cannot be told.
            if (isset($amounts[$variantId]) && ! $amounts[$variantId]->equals($money)) {
                throw new InvalidPricingInput('variant');
            }

            $amounts[$variantId] = $money;
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

                $this->retailPrices->save($storeId, $variantId, $amount, PriceSource::Staff);
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
