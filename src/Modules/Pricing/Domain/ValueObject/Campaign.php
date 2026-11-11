<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\Money;

/**
 * A campaign run's prices in one store (pricing.md §9.6): on retail lines, wholesale lines or both, from
 * its start to its end, on what it targets, less the products left out of its categories and brands. It
 * covers what is in a category or brand now, never a list kept when it was saved (content.md Q18 (a)).
 * Its life is read from the dates (§4.1), as a sale's.
 *
 * **Its price on a size** (the build's pick): the most particular target says it - the size's own, else
 * its product's, else the lowest of its categories' and its brand's, those two only for a product not
 * left out (products and sizes picked by name stay as picked). One price per run and size; between runs,
 * sales and discounts the lowest wins (§9.6 #3).
 */
final readonly class Campaign
{
    use DatedWindow;

    /** @var array<string, CampaignTarget> */
    private array $sizes;

    /** @var array<string, CampaignTarget> */
    private array $products;

    /** @var array<string, CampaignTarget> */
    private array $categories;

    /** @var array<string, CampaignTarget> */
    private array $brands;

    /** @var array<string, true> */
    private array $excluded;

    /**
     * @param  list<CampaignTarget>  $targets
     * @param  list<string>  $leftOut  product ids
     */
    public function __construct(
        public string $runId,
        public string $storeId,
        public DiscountLines $lines,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public array $targets,
        public array $leftOut,
    ) {
        $by = [];

        foreach ($targets as $target) {
            $by[$target->kind->value][$target->id] = $target;
        }

        $this->sizes = $by[CampaignTargetKind::Size->value] ?? [];
        $this->products = $by[CampaignTargetKind::Product->value] ?? [];
        $this->categories = $by[CampaignTargetKind::Category->value] ?? [];
        $this->brands = $by[CampaignTargetKind::Brand->value] ?? [];
        $this->excluded = array_fill_keys($leftOut, true);
    }

    /**
     * The target that prices a size, from what Catalog says of it - its product, brand and categories (its
     * own and every one above it). Null: the run does not reach it.
     *
     * @param  list<string>  $categoryIds
     */
    public function targetFor(string $variantId, string $productId, string $brandId, array $categoryIds): ?CampaignTarget
    {
        if (isset($this->sizes[$variantId])) {
            return $this->sizes[$variantId];
        }

        if (isset($this->products[$productId])) {
            return $this->products[$productId];
        }

        if (isset($this->excluded[$productId])) {
            return null;
        }

        $best = null;

        foreach ([...array_map(fn (string $id): ?CampaignTarget => $this->categories[$id] ?? null, $categoryIds), $this->brands[$brandId] ?? null] as $target) {
            if ($target !== null && ($best === null || self::cheaper($target, $best))) {
                $best = $target;
            }
        }

        return $best;
    }

    /**
     * How deep a target goes on a size of this retail price, in basis points: a percentage as it is; a
     * fixed price by its share of the retail price, rounded up - 0 for one at or above it.
     */
    public static function depthOn(CampaignTarget $target, Money $retail): int
    {
        if ($target->amount === null) {
            return $target->percentBasisPoints ?? 0;
        }

        return $retail->minorUnits <= 0 ? 0 : max(0, (int) ceil(($retail->minorUnits - $target->amount->minorUnits) * 10000 / $retail->minorUnits));
    }

    /** @return list<string> */
    public function targetIds(CampaignTargetKind $kind): array
    {
        return array_map('strval', array_keys(match ($kind) {
            CampaignTargetKind::Size => $this->sizes,
            CampaignTargetKind::Product => $this->products,
            CampaignTargetKind::Category => $this->categories,
            CampaignTargetKind::Brand => $this->brands,
        }));
    }

    /** Whether a fixed price is among its targets: only one of those can go past the ceiling at a provider's price. */
    public function hasFixedPrices(): bool
    {
        foreach ($this->sizes as $target) {
            if ($target->amount !== null) {
                return true;
            }
        }

        return false;
    }

    /** Categories and brands take a percentage only: the deeper is the cheaper; equal ones, by id, so the pick never wavers. */
    private static function cheaper(CampaignTarget $a, CampaignTarget $b): bool
    {
        return ($a->percentBasisPoints ?? 0) > ($b->percentBasisPoints ?? 0)
            || (($a->percentBasisPoints ?? 0) === ($b->percentBasisPoints ?? 0) && strcmp($a->id, $b->id) < 0);
    }
}
