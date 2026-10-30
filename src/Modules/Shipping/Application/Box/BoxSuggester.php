<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Box;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\ShippingSettings;
use Modules\Shipping\Domain\Model\Box;
use Modules\Shipping\Domain\Repository\BoxRepository;
use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Modules\Shipping\Public\Dto\ParcelLineDto;
use Modules\Shipping\Public\Enums\BoxSuggestionReason;
use Shared\Domain\ValueObject\StoreId;

/**
 * The box suggestion (shipping.md §1.7; owner, 2026-10-30, gap 5.2): the smallest switched-on box - by
 * inner volume, a tie to the first in the list - that takes every piece of the order. A box takes them
 * when, its inner sides each less the padding on both sides:
 *
 * - every piece fits on its own, turned as it must (its three sides sorted against the box's);
 * - the pieces' weights added up are no more than the box takes;
 * - the pieces' volumes added up are no more than the box's inner volume (no 3D packing - this only
 *   keeps ten chairs out of a box one chair fits).
 *
 * A size packed by hand, or one with no weight or sizes in Catalog, makes the whole order "pack by hand".
 * No switched-on box at all: nothing is suggested.
 */
final readonly class BoxSuggester
{
    public function __construct(
        private BoxRepository $boxes,
        private CatalogApi $catalog,
        private PlatformApi $platform,
    ) {}

    /**
     * @param  list<ParcelLineDto>  $lines
     */
    public function suggest(StoreId $store, array $lines): ?BoxSuggestionDto
    {
        $boxes = array_values(array_filter($this->boxes->inStore($store->value), static fn (Box $box): bool => $box->isActive()));

        if ($boxes === []) {
            return null;
        }

        $lines = array_values(array_filter($lines, static fn (ParcelLineDto $line): bool => $line->quantity > 0));

        // Nothing to pack: nothing to suggest.
        if ($lines === []) {
            return null;
        }

        $ids = array_values(array_unique(array_map(static fn (ParcelLineDto $line): string => strtolower($line->variantId), $lines)));

        if ($this->boxes->handPacked($store->value, $ids) !== []) {
            return BoxSuggestionDto::byHand(BoxSuggestionReason::ManualSize);
        }

        $variants = $this->catalog->variants($ids);
        $pieces = [];
        $weight = 0;
        $volume = 0;

        foreach ($lines as $line) {
            $variant = $variants[strtolower($line->variantId)] ?? null;

            if ($variant === null || $variant->weightGrams === null || $variant->lengthMm === null || $variant->widthMm === null || $variant->heightMm === null) {
                return BoxSuggestionDto::byHand(BoxSuggestionReason::NoSizeFacts);
            }

            $sides = [$variant->lengthMm, $variant->widthMm, $variant->heightMm];
            sort($sides);
            $pieces[] = $sides;
            $weight += $variant->weightGrams * $line->quantity;
            $volume += $sides[0] * $sides[1] * $sides[2] * $line->quantity;
        }

        $padding = $this->platform->setting(ShippingSettings::PADDING_MM, $store)->int();
        $best = null;
        $bestVolume = null;

        foreach ($boxes as $box) {
            $inner = array_map(static fn (int $side): int => $side - 2 * $padding, $box->sizes());
            sort($inner);

            if ($inner[0] <= 0 || $weight > $box->maxWeightG() || $volume > $inner[0] * $inner[1] * $inner[2]) {
                continue;
            }

            foreach ($pieces as $sides) {
                if ($sides[0] > $inner[0] || $sides[1] > $inner[1] || $sides[2] > $inner[2]) {
                    continue 2;
                }
            }

            [$length, $width, $height] = $box->sizes();
            $boxVolume = $length * $width * $height;

            // The smallest by its inner sizes as entered (§1.7); a tie keeps the first in the list.
            if ($bestVolume === null || $boxVolume < $bestVolume) {
                [$best, $bestVolume] = [$box, $boxVolume];
            }
        }

        return $best === null ? BoxSuggestionDto::byHand(BoxSuggestionReason::NothingFits) : BoxSuggestionDto::box($best->id, $best->name());
    }
}
