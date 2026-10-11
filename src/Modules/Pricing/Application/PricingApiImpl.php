<?php

declare(strict_types=1);

namespace Modules\Pricing\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Domain\Exception\DuplicateLines;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Service\OrderTotals;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\KeptPartDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Pricing\Public\Dto\TotalsDto;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * `PricingApi` (pricing.md §2.1): each line priced from the materialized candidates - one indexed read
 * for any number of lines, the scheduled "always wins" rows included so a price's end can be cut short
 * (§1.6 step 6) - and every amount of an order through `OrderTotals`. Module to module: no permission
 * is checked. A store that does not exist is invalid input (owner, 2026-10-11), as are a quantity
 * below 1 and a variant and mode given twice (`pricing.duplicate_lines`).
 */
final readonly class PricingApiImpl implements PricingApi
{
    public function __construct(
        private PlatformApi $platform,
        private PriceCandidates $candidates,
    ) {}

    public function prices(StoreId $store, array $lines): CartPricesDto
    {
        $found = $this->store($store);
        self::refuseRepeats($lines);

        return $this->priced($found, [], $lines, [], $found->taxRateBasisPoints);
    }

    public function totals(CartPricesDto $prices, Money $couponDiscount, Money $pointsDiscount, Money $shipping): TotalsDto
    {
        return OrderTotals::of($prices, $couponDiscount, $pointsDiscount, $shipping);
    }

    public function pricesForEdit(StoreId $store, array $kept, array $added, int $taxRateBasisPoints): CartPricesDto
    {
        $found = $this->store($store);
        self::refuseRepeats($added);

        // A wholesale band is chosen for the line's new total: the kept pieces and the added ones (§1.11).
        $keptPieces = [];

        foreach ($kept as $part) {
            if ($part->quantity < 1) {
                throw new InvalidPricingInput('quantity');
            }

            $key = self::key($part->variantId, $part->mode);
            $keptPieces[$key] = ($keptPieces[$key] ?? 0) + $part->quantity;
        }

        return $this->priced($found, $kept, $added, $keptPieces, $taxRateBasisPoints);
    }

    /**
     * @param  list<KeptPartDto>  $kept  as sold: their prices stand, and never end
     * @param  list<CartLineDto>  $lines  priced now
     * @param  array<string, int>  $keptPieces  variant and mode => pieces kept, for the band
     */
    private function priced(StoreDto $store, array $kept, array $lines, array $keptPieces, int $taxRateBasisPoints): CartPricesDto
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $ids = array_values(array_unique(array_map(static fn (CartLineDto $line): string => self::id($line->variantId), $lines)));
        $candidates = $this->candidates->forVariants($store->storeId(), $ids);
        $priced = [];
        $unpriced = [];
        $validUntil = null;

        foreach ($kept as $part) {
            $priced[] = new LinePriceDto(
                $part->variantId, $part->mode, $part->quantity, $part->listUnit, $part->unit, $part->kind,
                self::times($part->listUnit, $part->quantity), self::times($part->unit, $part->quantity), null,
            );
        }

        foreach ($lines as $line) {
            $id = self::id($line->variantId);
            $forBand = $line->quantity + ($keptPieces[self::key($line->variantId, $line->mode)] ?? 0);
            $price = PriceResolution::resolve($candidates[$id] ?? [], $line->mode, $forBand, $now);

            if ($price === null) {
                $unpriced[] = $line->variantId;

                continue;
            }

            $priced[] = new LinePriceDto(
                $line->variantId, $line->mode, $line->quantity, $price->listUnit, $price->unit, $price->kind,
                self::times($price->listUnit, $line->quantity), self::times($price->unit, $line->quantity), $price->endsAt,
            );
            $validUntil = self::earlier($validUntil, $price->endsAt);
        }

        $gross = Money::zero($store->currencyCode);
        $net = Money::zero($store->currencyCode);

        foreach ($priced as $line) {
            $gross = $gross->add($line->listTotal);
            $net = $net->add($line->total);
        }

        return new CartPricesDto($store->id, $store->currencyCode, $taxRateBasisPoints, $priced, $unpriced, $gross, $net, $now, $validUntil);
    }

    private function store(StoreId $store): StoreDto
    {
        return $this->platform->store($store) ?? throw new InvalidPricingInput('store');
    }

    /**
     * @param  list<CartLineDto>  $lines
     */
    private static function refuseRepeats(array $lines): void
    {
        $seen = [];

        foreach ($lines as $line) {
            if ($line->quantity < 1) {
                throw new InvalidPricingInput('quantity');
            }

            $key = self::key($line->variantId, $line->mode);

            if (isset($seen[$key])) {
                throw new DuplicateLines;
            }

            $seen[$key] = true;
        }
    }

    private static function id(string $variantId): string
    {
        return strtolower(trim($variantId));
    }

    private static function key(string $variantId, SaleMode $mode): string
    {
        return self::id($variantId).'|'.$mode->value;
    }

    private static function times(Money $unit, int $quantity): Money
    {
        return Money::of($unit->minorUnits * $quantity, $unit->currencyCode);
    }

    private static function earlier(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a <= $b ? $a : $b;
    }
}
