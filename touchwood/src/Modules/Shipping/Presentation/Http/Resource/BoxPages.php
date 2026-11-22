<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Shipping\Application\Query\ListBoxes\BoxRow;
use Modules\Shipping\Application\Query\ListBoxes\ListBoxes;
use Modules\Shipping\Application\Query\ListBoxes\ListBoxesHandler;
use Modules\Shipping\Application\Query\ListPackingSizes\ListPackingSizes;
use Modules\Shipping\Application\Query\ListPackingSizes\ListPackingSizesHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Shared\Application\Unauthorized;

/**
 * The Boxes screen's data (shipping.md §4.1 part 3), built from step 5's queries - which authorize - and the
 * sizes' photos from Platform's media in one read.
 */
final readonly class BoxPages
{
    /** What opens the Boxes screen of a store: managing boxes - there is no read-only view (§3). */
    public const array JOBS = [ShippingPermissions::BOXES_MANAGE];

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
    ) {}

    /**
     * X1.
     *
     * @throws Unauthorized
     */
    public function page(?string $storeCode, ?string $filter, ListBoxesHandler $boxes, ListPackingSizesHandler $sizes): BoxesPage
    {
        [$store, $offered] = $this->store($storeCode);
        $locale = $this->locale();
        $list = $boxes->handle(new ListBoxes($store->id));

        return new BoxesPage(
            $list->paddingMm,
            array_map(static fn (BoxRow $row): BoxData => new BoxData($row->id, $row->name, $row->lengthMm, $row->widthMm, $row->heightMm, $row->maxWeightG, $row->active), $list->boxes),
            $this->sizes($sizes->handle(new ListPackingSizes($store->id, true, $filter)), $locale),
            $filter,
            $store->code,
            $store->name->in($locale),
            $store->isActive,
            array_map(fn (StoreDto $each): StoreOptionData => new StoreOptionData($each->code, $each->name->in($locale), $each->isActive), $offered),
        );
    }

    /**
     * A page of sizes: the hand-packed list's next page (B12), or the Add Sizes search (B11) - the store's
     * sizes not yet packed by hand, nothing archived.
     *
     * @throws Unauthorized
     */
    public function search(?string $storeCode, bool $byHand, ?string $text, ?string $after, ListPackingSizesHandler $sizes): PackingSizesData
    {
        [$store] = $this->store($storeCode);

        return $this->sizes($sizes->handle(new ListPackingSizes($store->id, $byHand, $text, $after)), $this->locale());
    }

    /**
     * The store shown - the one asked for, else the first that is on among those where the reader may manage
     * boxes - and the stores offered.
     *
     * @return array{0: StoreDto, 1: list<StoreDto>}
     *
     * @throws Unauthorized
     */
    private function store(?string $code): array
    {
        $offered = $this->choices->forJobs(...self::JOBS);
        $chosen = null;

        foreach ($offered as $store) {
            if ($code === null ? $store->isActive : strtolower($store->code) === strtolower(trim($code))) {
                $chosen = $store;
                break;
            }
        }

        $chosen ??= $code === null ? ($offered[0] ?? null) : null;

        return [$chosen ?? throw new Unauthorized(ShippingPermissions::BOXES_MANAGE), $offered];
    }

    private function sizes(FoundVariantsDto $found, string $locale): PackingSizesData
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (FoundVariantDto $row): ?string => $row->photoMediaId, $found->rows), static fn (?string $id): bool => $id !== null && $id !== '')));
        $photos = [];

        foreach ($ids === [] ? [] : $this->platform->mediaUrlsOf($ids) as $id => $urls) {
            $photos[strtolower((string) $id)] = self::thumb($urls);
        }

        return new PackingSizesData(
            array_map(static fn (FoundVariantDto $row): PackingSizeData => new PackingSizeData(
                $row->variantId,
                $locale === 'en' && $row->productNameEn !== null && $row->productNameEn !== '' ? $row->productNameEn : $row->productNameAr,
                $row->code,
                implode(' · ', array_map(static fn (VariantValueDto $value): string => $value->value->in($locale), $row->values)),
                $row->photoMediaId === null ? null : ($photos[strtolower($row->photoMediaId)] ?? null),
                $row->archived,
            ), $found->rows),
            $found->next,
            $found->total,
        );
    }

    private static function thumb(MediaUrlsDto $urls): ?string
    {
        $sizes = $urls->variants[MediaSize::Thumb->slug()] ?? [];

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $sizes[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
