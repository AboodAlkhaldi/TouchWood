<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Import;

use Modules\Catalog\Public\Contracts\ImportSection;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\StoreWiring;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Infrastructure\Queue\SetPriceFromFileJob;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Pricing's part of a store file** (pricing.md §1.8; catalog.md §2.3): the file's page says the retail
 * price each item will set, or why not - more decimals than the store's currency has, or 0 (owner,
 * 2026-10-07: refused, never rounded), or not a number; in a wired store, ignored. When an item is
 * switched on, its price is set by a job queued once Catalog's change commits
 * (`SetPriceFromFileHandler` says why not inside it). An item with no price leaves the size's price.
 */
final readonly class PriceImportSection implements ImportSection
{
    public function __construct(
        private PlatformApi $platform,
        private StoreWiring $wiring,
    ) {}

    public function lines(StoreId $store, ?string $price, ?int $stock): array
    {
        $found = $this->platform->store($store);

        if ($price === null || $found === null) {
            return [];
        }

        if ($this->wiring->isWired($store)) {
            return [trans('pricing::import.price_ignored')];
        }

        try {
            AmountInput::of($price, $found);
        } catch (PriceTooPrecise) {
            return [trans('pricing::import.price_too_precise', ['price' => $price, 'decimals' => $found->currencyExponent])];
        } catch (PriceNotPositive) {
            return [trans('pricing::import.price_not_positive', ['price' => $price])];
        } catch (DomainError) {
            return [trans('pricing::import.price_invalid', ['price' => $price])];
        }

        return [trans('pricing::import.price_set', ['price' => $price, 'currency' => $found->currencyCode])];
    }

    public function accepted(StoreId $store, array $variantIds, ?string $price, ?int $stock): void
    {
        $found = $this->platform->store($store);

        if ($price === null || $found === null || $variantIds === [] || $this->wiring->isWired($store)) {
            return;
        }

        try {
            AmountInput::of($price, $found);
        } catch (DomainError) {
            // The page said why it is not set.
            return;
        }

        SetPriceFromFileJob::dispatch($store->value, $variantIds, $price)->afterCommit();
    }
}
