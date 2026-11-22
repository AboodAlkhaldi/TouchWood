<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetRetailPrice;

use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\RetailPriceWriter;
use Modules\Pricing\Application\Prices\SizeInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Exception\ProviderOwnsPrice;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Modules\Pricing\Public\Contracts\StoreWiring;
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
        private RetailPriceWriter $prices,
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

            // A refusal names the size it is about, so the screen marks its row (owner Q2).
            try {
                $money = AmountInput::of($amount, $store);
            } catch (PriceTooPrecise $refused) {
                throw new PriceTooPrecise($refused->decimals, [(string) $given]);
            } catch (PriceNotPositive) {
                throw new PriceNotPositive([(string) $given]);
            } catch (InvalidPricingInput $refused) {
                throw new InvalidPricingInput($refused->attribute, [(string) $given]);
            }

            // One size written two ways with two prices: which was meant cannot be told.
            if (isset($amounts[$variantId]) && ! $amounts[$variantId]->equals($money)) {
                throw new InvalidPricingInput('variant', [(string) $given]);
            }

            $amounts[$variantId] = $money;
        }

        if ($amounts === []) {
            return;
        }

        // A price that would take a running or scheduled sale past the ceiling is held back, naming the
        // sales and their products: staff change the price or the sale first (owner, 2026-10-11/13).
        $this->prices->set($store, $amounts, PriceSource::Staff, 'pricing.retail_price.set');
    }
}
