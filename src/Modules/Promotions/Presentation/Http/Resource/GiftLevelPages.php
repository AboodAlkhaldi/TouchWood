<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use DateTimeImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Promotions\Application\Query\GiftLevelRow;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevels;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevelsHandler;
use Modules\Promotions\Application\Query\PromotionsActions\PromotionsActionsForReader;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The gift levels screen's data (promotions.md §4.1 M4), built from Promotions' Application queries -
 * which authorize - and Catalog's size search for the picker, asked only for a manager who searched.
 */
final readonly class GiftLevelPages
{
    /** How many sizes the picker shows for what was typed. */
    private const int FOUND = 20;

    public function __construct(
        private MarketingScreen $screen,
        private CatalogApi $catalog,
        private PlatformApi $platform,
        private PromotionsActionsForReader $actions,
    ) {}

    /**
     * M4.
     *
     * @throws Unauthorized
     */
    public function list(?string $storeCode, ?string $search, ListGiftLevelsHandler $levels): GiftLevelsPage
    {
        [$store, $offered] = $this->screen->store($storeCode);
        $rows = $levels->handle(new ListGiftLevels($store->id));
        // Highest first (owner's Q10): the order an order is matched in (§1.7).
        usort($rows, static fn (GiftLevelRow $a, GiftLevelRow $b): int => $b->amount->minorUnits <=> $a->amount->minorUnits);
        $mayManage = $this->actions->forStore($store->storeId())->mayManageGifts;
        $exponent = $store->currencyExponent;
        $now = new DateTimeImmutable;

        return new GiftLevelsPage(
            array_map(fn (GiftLevelRow $row): GiftLevelData => new GiftLevelData(
                $row->id,
                MoneyData::of($row->amount, $exponent),
                MoneyData::decimal($row->amount->minorUnits, $exponent),
                $row->variantId,
                $row->variantCode,
                $row->productNameAr === null ? null : $this->screen->name($row->productNameAr, $row->productNameEn),
                $row->variantArchived,
                $row->forPublic,
                $row->forCompany,
                $row->startsAt === null ? null : MarketingScreen::moment($row->startsAt),
                $row->endsAt === null ? null : MarketingScreen::moment($row->endsAt),
                $row->active,
                match (true) {
                    ! $row->active => 'OFF',
                    $row->inEffect => 'GIVING',
                    $row->startsAt !== null && $row->startsAt > $now => 'NOT_STARTED',
                    default => 'ENDED',
                },
            ), $rows),
            $search === null || ! $mayManage ? [] : $this->sizes($store->storeId(), $search),
            $store->currencyCode,
            $exponent,
            $mayManage,
            ...$this->screen->shown($store),
            stores: $this->screen->options($offered),
        );
    }

    /**
     * The store's sizes matching what was typed, by product name or size code - archived ones left out -
     * with their photos' thumbnails, in one read.
     *
     * @return list<SizeChoiceData>
     */
    private function sizes(StoreId $store, string $search): array
    {
        $found = $this->catalog->searchVariants($store, $search, anyReady: true, limit: self::FOUND, notArchived: true, withTotal: false)->rows;
        $ids = array_values(array_unique(array_filter(array_map(static fn (FoundVariantDto $row): ?string => $row->photoMediaId, $found))));
        $thumbs = [];

        foreach ($ids === [] ? [] : $this->platform->mediaUrlsOf($ids) as $id => $urls) {
            $thumbs[strtolower((string) $id)] = self::thumb($urls);
        }

        return array_map(fn (FoundVariantDto $row): SizeChoiceData => new SizeChoiceData(
            $row->variantId,
            $this->screen->name($row->productNameAr, $row->productNameEn),
            $row->code,
            $row->photoMediaId === null ? null : ($thumbs[strtolower($row->photoMediaId)] ?? null),
        ), $found);
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
}
