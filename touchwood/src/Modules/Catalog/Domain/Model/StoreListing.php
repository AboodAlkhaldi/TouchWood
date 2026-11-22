<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidSellingTerms;
use Modules\Catalog\Domain\Exception\NotChosenInStore;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\ValueObject\SellingLimits;

/**
 * **One store's choice of one product** (catalog.md §1.3): which of its variants the store sells,
 * how — retail, wholesale or both — and how many to one order, whether it is "Not available now",
 * and the labels the store shows on it. Price and stock are not here (Pricing, Inventory).
 *
 * It keeps what its own rows can know: a variant first chosen sells retail only (amendment 4(e)); a
 * variant switched off keeps its row, so choosing it again brings back how it sold; selling terms,
 * labels and "Not available now" need a product the store has chosen (`NotChosenInStore`). Which
 * variants may be switched on — a ready product's, not archived — and which labels may be attached
 * are questions about other rows, its handlers'.
 */
final class StoreListing
{
    /** Labels on one product in one store (amendment 4(e)). */
    public const int MAX_LABELS = 10;

    private ChangeLog $changes;

    /**
     * @param  array<string, array{active: bool, unavailable: bool, retail: bool, wholesale: bool}>  $variants  variant id => its row
     * @param  list<string>  $labelIds
     */
    private function __construct(
        private readonly string $storeId,
        private readonly string $productId,
        private bool $chosen,
        private bool $notAvailableNow,
        private SellingLimits $limits,
        private array $variants,
        private array $labelIds,
    ) {
        $this->changes = new ChangeLog;
    }

    /** A product this store has never chosen: no rows yet. */
    public static function unchosen(string $storeId, string $productId): self
    {
        return new self($storeId, $productId, false, false, SellingLimits::initial(), [], []);
    }

    /**
     * @param  array<string, array{active: bool, unavailable: bool, retail: bool, wholesale: bool}>  $variants
     * @param  list<string>  $labelIds
     */
    public static function reconstitute(string $storeId, string $productId, bool $notAvailableNow, SellingLimits $limits, array $variants, array $labelIds): self
    {
        return new self($storeId, $productId, true, $notAvailableNow, $limits, $variants, $labelIds);
    }

    /**
     * Switches these variants on or off in the store. A variant first chosen sells retail only; one
     * never chosen has nothing to switch off.
     *
     * @param  list<string>  $variantIds
     */
    public function choose(array $variantIds, bool $active): void
    {
        $before = $this->snapshot();

        foreach ($variantIds as $variantId) {
            if (isset($this->variants[$variantId])) {
                $this->variants[$variantId]['active'] = $active;
            } elseif ($active) {
                $this->variants[$variantId] = ['active' => true, 'unavailable' => false, 'retail' => true, 'wholesale' => false];
                $this->chosen = true;
            }
        }

        $this->record($before);
    }

    /**
     * Each variant's selling modes and the product's limits (§1.3): at least one mode a variant, and a
     * wholesale minimum while any variant sells wholesale.
     *
     * @param  array<string, array{retail: bool, wholesale: bool}>  $modes  variant id => its modes
     *
     * @throws InvalidSellingTerms|NotChosenInStore
     */
    public function setTerms(array $modes, SellingLimits $limits): void
    {
        $this->requireChosen();
        $before = $this->snapshot();

        foreach ($modes as $variantId => $mode) {
            if (! isset($this->variants[$variantId])) {
                throw new NotChosenInStore;
            }

            if (! $mode['retail'] && ! $mode['wholesale']) {
                throw new InvalidSellingTerms('a selling mode for every variant');
            }

            $this->variants[$variantId]['retail'] = $mode['retail'];
            $this->variants[$variantId]['wholesale'] = $mode['wholesale'];
        }

        foreach ($this->variants as $row) {
            if ($row['wholesale'] && $limits->wholesaleMinimum === null) {
                throw new InvalidSellingTerms('a wholesale minimum while any variant sells wholesale');
            }
        }

        $this->limits = $limits;
        $this->record($before);
    }

    /**
     * "Not available now" on the whole product — every variant, later ones too — or on one variant.
     *
     * @throws NotChosenInStore
     */
    public function markUnavailable(?string $variantId, bool $unavailable): void
    {
        $this->requireChosen();
        $before = $this->snapshot();

        if ($variantId === null) {
            $this->notAvailableNow = $unavailable;
        } elseif (isset($this->variants[$variantId])) {
            $this->variants[$variantId]['unavailable'] = $unavailable;
        } else {
            throw new NotChosenInStore;
        }

        $this->record($before);
    }

    /**
     * At most ten labels on a product in a store — counted by the handler before any label is looked
     * up, so a request of thousands is refused without reading one.
     *
     * @throws TooMany
     */
    public static function checkLabelCount(int $count): void
    {
        if ($count > self::MAX_LABELS) {
            throw new TooMany('labels', self::MAX_LABELS);
        }
    }

    /**
     * The labels the store shows on the product, each once — their count already checked
     * (`checkLabelCount`).
     *
     * @param  list<string>  $labelIds
     *
     * @throws InvalidCatalogAttribute|NotChosenInStore
     */
    public function attachLabels(array $labelIds): void
    {
        $this->requireChosen();

        if (count(array_unique($labelIds)) !== count($labelIds)) {
            throw new InvalidCatalogAttribute('labels', 'each label once');
        }

        $before = $this->snapshot();
        $this->labelIds = $labelIds;
        $this->record($before);
    }

    /**
     * Every variant switched off — its product or itself archived (§1.3, §4.2); restoring leaves
     * them off.
     *
     * @param  list<string>|null  $variantIds  null for every variant
     */
    public function switchOff(?array $variantIds = null): void
    {
        $this->choose($variantIds ?? array_keys($this->variants), false);
    }

    public function storeId(): string
    {
        return $this->storeId;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    /** Whether the store has ever chosen it: its rows exist. */
    public function isChosen(): bool
    {
        return $this->chosen;
    }

    public function notAvailableNow(): bool
    {
        return $this->notAvailableNow;
    }

    public function limits(): SellingLimits
    {
        return $this->limits;
    }

    /**
     * @return array<string, array{active: bool, unavailable: bool, retail: bool, wholesale: bool}>
     */
    public function variants(): array
    {
        return $this->variants;
    }

    /**
     * @return list<string>
     */
    public function activeVariantIds(): array
    {
        return $this->idsWhere('active');
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->labelIds;
    }

    /**
     * Every column by value, the variants' as their ids in order, for the audit log.
     *
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        // Labels as a set: a card shows them in the list's order, so their order here changes nothing.
        $labels = $this->labelIds;
        sort($labels);

        return [
            'active_variants' => self::joined($this->idsWhere('active')),
            'not_available_now' => $this->notAvailableNow,
            'unavailable_variants' => self::joined($this->idsWhere('unavailable')),
            'retail_variants' => self::joined($this->idsWhere('retail')),
            'wholesale_variants' => self::joined($this->idsWhere('wholesale')),
            'retail_minimum' => $this->limits->retailMinimum,
            'retail_maximum' => $this->limits->retailMaximum,
            'wholesale_minimum' => $this->limits->wholesaleMinimum,
            'wholesale_maximum' => $this->limits->wholesaleMaximum,
            'labels' => self::joined($labels),
        ];
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function pullChanges(): array
    {
        return $this->changes->pull();
    }

    /**
     * @throws NotChosenInStore
     */
    private function requireChosen(): void
    {
        if (! $this->chosen) {
            throw new NotChosenInStore;
        }
    }

    /**
     * @param  'active'|'unavailable'|'retail'|'wholesale'  $flag
     * @return list<string>
     */
    private function idsWhere(string $flag): array
    {
        $ids = array_keys(array_filter($this->variants, static fn (array $row): bool => $row[$flag]));
        sort($ids);

        return array_map('strval', $ids);
    }

    /**
     * @param  array<string, string|int|bool|null>  $before
     */
    private function record(array $before): void
    {
        foreach ($this->snapshot() as $column => $now) {
            $this->changes->record($column, $before[$column], $now);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private static function joined(array $ids): ?string
    {
        return $ids === [] ? null : implode(',', $ids);
    }
}
