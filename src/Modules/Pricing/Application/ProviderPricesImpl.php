<?php

declare(strict_types=1);

namespace Modules\Pricing\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleAudit;
use Modules\Pricing\Application\Prices\SizeInput;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Public\Contracts\ProviderPrices;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Modules\Pricing\Public\Dto\ProviderPricesResult;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The provider's prices in (pricing.md §2.6; sync.md §1.4). Each batch is its own transaction under the
 * store's lock, as every change (`PricingChange`): the prices, the sales the ceiling stops on a size,
 * their audit entries and the candidates written again together. Called from Sync's queued read, never
 * inside Catalog's lock, so the store's lock and then Catalog's are taken in the order every change
 * takes them.
 */
final readonly class ProviderPricesImpl implements ProviderPrices
{
    /** Sizes a transaction: a big store's checkout never waits on a whole read. */
    private const int CHUNK = 500;

    public function __construct(
        private StoreWiring $wiring,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private PricingChange $change,
        private SizeInput $sizes,
        private RetailPrices $retailPrices,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
        private CeilingCheck $ceiling,
        private CandidateWriter $writer,
    ) {}

    public function apply(StoreId $store, array $prices, string $readRef): ProviderPricesResult
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $readRef) !== 1) {
            throw new InvalidArgumentException('A read reference is letters, digits, - and _, at most 64.');
        }

        $found = $this->platform->store($store) ?? throw new InvalidArgumentException('No such store.');

        if (! $this->wiring->isWired($store)) {
            return new ProviderPricesResult(wired: false);
        }

        $amounts = $invalid = [];

        foreach ($prices as $variantId => $price) {
            // The store's own rules for a price (§1.2): refused, never rounded; the provider gives no
            // currency, so it is the store's (sync.md §9.1).
            try {
                // Sync passes the provider's text; anything else is not a price (sync.md §1.7: the
                // read goes on).
                $amounts[(string) $variantId] = is_string($price) ? AmountInput::of($price, $found) : throw new InvalidPricingInput('amount');
            } catch (InvalidPricingInput|PriceTooPrecise|PriceNotPositive) {
                $invalid[] = (string) $variantId;
            }
        }

        [$own, $archived, $notFound] = $this->sizes->sort(array_keys($amounts));
        $switchedOn = array_flip($this->catalog->switchedOnVariantIds($store));
        // By the size's own id: [the id as given, its price, off or a draft].
        $given = [];

        foreach ($own as $id => [$variantId, , $draft]) {
            // Two ids written for one size: the first is taken, the other named, never dropped silently.
            if (isset($given[$variantId])) {
                $invalid[] = $id;

                continue;
            }

            $given[$variantId] = [$id, $amounts[$id], $draft || ! isset($switchedOn[$variantId])];
        }

        ksort($given, SORT_STRING);
        $changed = $offOrDraft = $salesStopped = $discountsSkipped = $changes = [];
        $wired = true;

        foreach (array_chunk($given, self::CHUNK, true) as $chunk) {
            $done = $this->change->run($store, fn (): array => $this->applyChunk($store, $found, $chunk, $readRef));

            // Unwired between two batches: what is left is staff's now (sync.md §1.8).
            if ($done === null) {
                $wired = false;

                break;
            }

            array_push($changed, ...$done['changed']);
            array_push($offOrDraft, ...$done['offOrDraft']);
            array_push($salesStopped, ...$done['salesStopped']);
            array_push($discountsSkipped, ...$done['discountsSkipped']);
            array_push($changes, ...$done['changes']);
        }

        return new ProviderPricesResult($wired, $changed, $offOrDraft, $salesStopped, $discountsSkipped, $archived, $notFound, $invalid, $changes);
    }

    /**
     * @param  array<string, array{string, Money, bool}>  $chunk  own id => [the id as given, its price, off or a draft]
     * @return array{array{changed: list<string>, offOrDraft: list<string>, salesStopped: list<array{saleId: string, variantId: string, outcome: 'left'|'ended'|'removed'}>, discountsSkipped: list<array{discountId: string, variantId: string}>, changes: list<array{variantId: string, before: string|null, after: string}>}|null, list<AuditEntryDto>}
     */
    private function applyChunk(StoreId $store, StoreDto $found, array $chunk, string $readRef): array
    {
        // Asked again under the store's lock, which unwiring's changes take too.
        if (! $this->wiring->isWired($store)) {
            return [null, []];
        }

        $before = $this->retailPrices->forVariants($store, array_keys($chunk));
        $new = [];

        foreach ($chunk as $variantId => [, $amount]) {
            if (! isset($before[$variantId]) || ! $before[$variantId]->equals($amount)) {
                $new[$variantId] = $amount;
            }
        }

        $done = ['changed' => [], 'offOrDraft' => [], 'salesStopped' => [], 'discountsSkipped' => [], 'changes' => []];

        if ($new === []) {
            return [$done, []];
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $ceiling = $this->ceiling->ceiling($store) * 100;
        $entries = [];

        // A sale the new price takes past the ceiling stops on that size (sync.md §1.4 #5, §9.2 #16).
        foreach ($this->sales->notEndedOn($store, array_keys($new), $now) as $sale) {
            $over = array_values(array_filter($sale->variantIds, static fn (string $size): bool => isset($new[$size]) && $sale->depthOn($new[$size]) > $ceiling));

            if ($over === []) {
                continue;
            }

            $left = array_values(array_diff($sale->variantIds, $over));
            [$outcome, $after] = match (true) {
                $left !== [] => ['left', self::withSizes($sale, $left, $sale->endsAt)],
                // Started this very moment, it has no time to end at: removed, as one not started.
                $sale->running($now) && $sale->startsAt < $now => ['ended', self::withSizes($sale, $sale->variantIds, $now)],
                default => ['removed', null],
            };

            $after === null ? $this->sales->remove($sale->id) : $this->sales->save($after);
            $entries[] = SaleAudit::entry(match ($outcome) {
                'left' => 'pricing.sale.changed',
                'ended' => 'pricing.sale.ended',
                'removed' => 'pricing.sale.removed',
            }, $sale, $after, $found->currencyExponent);

            foreach ($over as $size) {
                $done['salesStopped'][] = ['saleId' => $sale->id, 'variantId' => $chunk[$size][0], 'outcome' => $outcome];
            }
        }

        // A fixed amount the new price takes past the ceiling skips the size (§9.3 #17): the candidate
        // writer leaves it out; named here so Sync reports it.
        $productOf = array_map(static fn ($variant): string => $variant->productId, $this->catalog->variants(array_keys($new)));

        foreach ($this->discounts->notEnded($store, $now) as $discount) {
            if ($discount->percentBasisPoints !== null) {
                continue;
            }

            $reaches = $this->coverage->products($discount);

            foreach ($new as $size => $amount) {
                if (isset($reaches[$productOf[$size] ?? '']) && $discount->priceOn($amount) !== null && $discount->depthOn($amount) > $ceiling) {
                    $done['discountsSkipped'][] = ['discountId' => $discount->id, 'variantId' => $chunk[$size][0]];
                }
            }
        }

        foreach ($new as $variantId => $amount) {
            $this->retailPrices->save($store, $variantId, $amount, PriceSource::Provider);
            $entries[] = new AuditEntryDto(
                'pricing.retail_price.provided',
                'pricing.retail_price',
                $variantId,
                $store->value,
                AuditChanges::none()
                    ->changed('amount', ($before[$variantId] ?? null)?->toDecimal($found->currencyExponent), $amount->toDecimal($found->currencyExponent))
                    ->changed('read', null, $readRef),
            );
            $done['changed'][] = $chunk[$variantId][0];
            $done['changes'][] = ['variantId' => $chunk[$variantId][0], 'before' => ($before[$variantId] ?? null)?->toDecimal($found->currencyExponent), 'after' => $amount->toDecimal($found->currencyExponent)];

            if ($chunk[$variantId][2]) {
                $done['offOrDraft'][] = $chunk[$variantId][0];
            }
        }

        // A stopped sale changes only sizes whose price changed: one left keeps the others; one that ends
        // or goes had every size here.
        $this->writer->refresh($store, array_keys($new));

        return [$done, $entries];
    }

    /**
     * @param  list<string>  $sizes
     */
    private static function withSizes(Sale $sale, array $sizes, ?DateTimeImmutable $endsAt): Sale
    {
        return new Sale($sale->id, $sale->storeId, $sale->productId, $sale->percentBasisPoints, $sale->price, $sale->startsAt, $endsAt, $sale->alwaysWins, $sizes);
    }
}
