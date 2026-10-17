<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RemoveRetailPrice;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SizeInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\ProviderOwnsPrice;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Application\Unauthorized;

/**
 * **Removing retail prices** in a store (pricing.md §1.2, §3), under **Edit Prices and Sales** there:
 * each size goes off sale in that store (no price, no sale) and back on Needs a Price (owner,
 * 2026-10-08). Audited from the price to none; a size with no price changes nothing. An archived size
 * is read-only until restored (owner, 2026-10-11); a wired store's prices are the provider's (§1.1
 * rule 9).
 */
final readonly class RemoveRetailPriceHandler
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
     * @throws Unauthorized|InvalidPricingInput|StoreOff|ProviderOwnsPrice
     */
    public function handle(RemoveRetailPrice $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if ($this->wiring->isWired($storeId)) {
            throw new ProviderOwnsPrice;
        }

        $variantIds = array_values(array_unique($this->sizes->check($command->variantIds)));

        if ($variantIds === []) {
            return;
        }

        $this->change->run($storeId, function () use ($storeId, $store, $variantIds): array {
            $before = $this->retailPrices->forVariants($storeId, $variantIds);
            $removed = [];
            $entries = [];

            foreach ($variantIds as $variantId) {
                if (! isset($before[$variantId])) {
                    continue;
                }

                $this->retailPrices->remove($storeId, $variantId);
                $removed[] = $variantId;
                $entries[] = new AuditEntryDto(
                    'pricing.retail_price.removed',
                    'pricing.retail_price',
                    $variantId,
                    $storeId->value,
                    AuditChanges::none()->changed('amount', $before[$variantId]->toDecimal($store->currencyExponent), null),
                );
            }

            $this->writer->refresh($storeId, $removed);

            return [null, $entries];
        });
    }
}
