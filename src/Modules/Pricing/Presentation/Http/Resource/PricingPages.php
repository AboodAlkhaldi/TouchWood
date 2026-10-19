<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\Query\CategoryChoices\CategoryChoice;
use Modules\Pricing\Application\Query\CategoryChoices\CategoryChoices;
use Modules\Pricing\Application\Query\CategoryChoices\CategoryChoicesHandler;
use Modules\Pricing\Application\Query\FindLeftOutProducts\FindLeftOutProducts;
use Modules\Pricing\Application\Query\FindLeftOutProducts\FindLeftOutProductsHandler;
use Modules\Pricing\Application\Query\FindSaleProducts\FindSaleProducts;
use Modules\Pricing\Application\Query\FindSaleProducts\FindSaleProductsHandler;
use Modules\Pricing\Application\Query\FindWholesaleSizes\FindWholesaleSizes;
use Modules\Pricing\Application\Query\FindWholesaleSizes\FindWholesaleSizesHandler;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscounts;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscountsHandler;
use Modules\Pricing\Application\Query\ListPrices\ListPrices;
use Modules\Pricing\Application\Query\ListPrices\ListPricesHandler;
use Modules\Pricing\Application\Query\ListPrices\PriceRow;
use Modules\Pricing\Application\Query\ListSaleProducts\ListSaleProducts;
use Modules\Pricing\Application\Query\ListSaleProducts\ListSaleProductsHandler;
use Modules\Pricing\Application\Query\ListSales\ListSales;
use Modules\Pricing\Application\Query\ListSales\ListSalesHandler;
use Modules\Pricing\Application\Query\ListWholesale\ListWholesale;
use Modules\Pricing\Application\Query\ListWholesale\ListWholesaleHandler;
use Modules\Pricing\Application\Query\ListWholesale\WholesaleRow;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPrice;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPriceHandler;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingDiscount;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingSale;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsFor;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsForHandler;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\DiscountPreview;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewCategoryDiscount;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewCategoryDiscountHandler;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewProduct;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewSize;
use Modules\Pricing\Application\Query\PriceViews\SizeView;
use Modules\Pricing\Application\Query\PricingActions\PricingActionsForReader;
use Modules\Pricing\Application\Query\SaleSizeChoices\SaleSizeChoice;
use Modules\Pricing\Application\Query\SaleSizeChoices\SaleSizeChoices;
use Modules\Pricing\Application\Query\SaleSizeChoices\SaleSizeChoicesHandler;
use Modules\Pricing\Application\Query\ViewCategoryDiscount\ViewCategoryDiscount;
use Modules\Pricing\Application\Query\ViewCategoryDiscount\ViewCategoryDiscountHandler;
use Modules\Pricing\Application\Query\ViewSale\ViewSale;
use Modules\Pricing\Application\Query\ViewSale\ViewSaleHandler;
use Modules\Pricing\Application\Query\ViewSizePrices\ViewSizePrices;
use Modules\Pricing\Application\Query\ViewSizePrices\ViewSizePricesHandler;
use Shared\Application\Unauthorized;
use Spatie\LaravelData\Data;

/**
 * Pricing's screens' data, in the shape the pages want (pricing.md §4.3, stage 5's screens step 3).
 *
 * It decides nothing: what the reader may read and do is Pricing's answer - the reads, and
 * `PricingActionsForReader` - and which store a page shows is Platform's `StoreChoices`, as on every
 * store screen (frontend.md §2.2): the stores where the reader holds the screen's job, a Super Admin's off
 * ones too; the first that is on when none is asked; any other refused (403). What happens here is
 * shaping: the store's code into its id, the rows' photos into thumbnails (one read a page), the reads'
 * answers into the pages' data.
 */
final readonly class PricingPages
{
    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
        private PricingActionsForReader $actions,
        private ListPricesHandler $prices,
        private ViewSizePricesHandler $size,
        private ListSalesHandler $sales,
        private ViewSaleHandler $sale,
        private ListSaleProductsHandler $saleProducts,
        private FindSaleProductsHandler $findProducts,
        private SaleSizeChoicesHandler $saleSizes,
        private OverlapsForHandler $overlaps,
        private ListCategoryDiscountsHandler $discounts,
        private ViewCategoryDiscountHandler $discount,
        private PreviewCategoryDiscountHandler $preview,
        private CategoryChoicesHandler $categories,
        private FindLeftOutProductsHandler $leftOut,
        private ListWholesaleHandler $wholesale,
        private FindWholesaleSizesHandler $wholesaleSizes,
        private NeedsAPriceHandler $needsAPrice,
    ) {}

    /**
     * The store a screen shows, by the code in its address - the first that is on when none is asked.
     *
     * @param  list<string>  $jobs  the screen's jobs, any of which offers a store
     *
     * @throws Unauthorized when it is not one of the reader's, or the reader has none
     */
    public function store(?string $code, array $jobs = ReadAccess::ANY_OF_THE_THREE): StoreDto
    {
        return $this->choices->chosen($code, ...$jobs) ?? throw new Unauthorized($jobs[0] ?? PricingPermissions::PRICE_EDIT);
    }

    /**
     * The store an action names - always named: a form that names none is refused, never sent to the first
     * store (as B2B's type lists, b2b.md amendment 30). The handler then asks for its own job there.
     *
     * @throws Unauthorized
     */
    public function storeId(?string $code): string
    {
        if ($code === null || trim($code) === '') {
            throw new Unauthorized(PricingPermissions::PRICE_EDIT);
        }

        return $this->store($code)->id;
    }

    /**
     * @param  list<string>  $jobs
     *
     * @throws Unauthorized
     */
    public function frame(StoreDto $store, array $jobs = ReadAccess::ANY_OF_THE_THREE): PricingFrameData
    {
        $actions = $this->actions->forStore($store->id) ?? throw new Unauthorized($jobs[0] ?? PricingPermissions::PRICE_EDIT);
        $reasons = $this->actions->reasons();
        $locale = $this->locale();

        return new PricingFrameData(
            $store->code,
            $store->name->in($locale),
            array_map(static fn (StoreDto $each): PricingStoreOptionData => new PricingStoreOptionData($each->code, $each->name->in($locale), $each->isActive), $this->choices->forJobs(...$jobs)),
            $actions->off,
            $actions->wired,
            $actions->ceilingPercent,
            $actions->maySetCeiling,
            $actions->mayEditPrices,
            $actions->mayEditWholesale,
            $actions->mayManageDiscounts,
            $actions->mayViewProducts,
            $store->currencyExponent,
            $store->currencyAbbreviation->in($locale),
            new PricingReasonsData($reasons->noPrice, $reasons->notSoldWholesale, $reasons->archived, $reasons->wiredPrice),
        );
    }

    public function prices(?string $code, ?string $search, string $filter, ?string $after): PricesPage
    {
        $store = $this->store($code);
        $list = $this->prices->handle(new ListPrices($store->id, $search, $filter, $after));
        $thumbs = $this->thumbs(array_map(static fn (PriceRow $row): ?string => $row->size->photoMediaId, $list->rows));

        return new PricesPage(
            $this->frame($store),
            $store->timezone,
            $search ?? '',
            $filter,
            array_map(fn (PriceRow $row): PriceRowData => new PriceRowData(
                $this->sizeData($row->size, $thumbs),
                $row->retail === null ? null : self::same(RetailPriceData::class, $row->retail),
                $row->today === null ? null : self::same(TodayPriceData::class, $row->today),
                $row->wholesaleOnly,
                $row->bands,
                $row->keptNotSoldWholesale,
                $row->firstBand === null ? null : self::same(BandData::class, $row->firstBand),
                $row->soldWholesale,
            ), $list->rows),
            $list->next,
            $list->total,
        );
    }

    /** A size's drawer, or its bands dialog's facts (P1, P4, the product page). */
    public function sizePrices(string $storeId, string $variantId): SizePricesData
    {
        $view = $this->size->handle(new ViewSizePrices($storeId, $variantId));

        return new SizePricesData(
            $this->sizeData($view->size, $this->thumbs([$view->size->photoMediaId])),
            $view->retail === null ? null : self::same(RetailPriceData::class, $view->retail),
            $view->todayRetail === null ? null : self::same(TodayPriceData::class, $view->todayRetail),
            $view->todayWholesale === null ? null : self::same(TodayPriceData::class, $view->todayWholesale),
            $view->wholesaleQuantity,
            $view->soldRetail,
            $view->soldWholesale,
            array_map(static fn (object $view): ReachingSaleData => self::same(ReachingSaleData::class, $view), $view->sales),
            array_map(static fn (object $view): ReachingDiscountData => self::same(ReachingDiscountData::class, $view), $view->discounts),
            array_map(static fn (object $view): BandData => self::same(BandData::class, $view), $view->bands),
            $view->wholesaleMinimum,
            $view->wholesaleMaximum,
            $view->minimumChanged,
        );
    }

    public function offers(?string $code, ?string $state, string $sort, ?string $productId, ?string $saleId, ?string $after): OffersPage
    {
        $store = $this->store($code);
        $list = $this->sales->handle(new ListSales($store->id, $state, $productId, $saleId, $sort, $after));
        $first = $list->sales[0] ?? null;

        return new OffersPage(
            $this->frame($store),
            $store->timezone,
            $state ?? '',
            $sort,
            $productId === null ? null : new PickedProductData($productId, $first->productNameAr ?? '', $first?->productNameEn, null, []),
            $saleId,
            array_map(static fn (object $view): SaleRowData => self::same(SaleRowData::class, $view), $list->sales),
            $list->next,
            $list->total,
        );
    }

    public function saleView(string $saleId): SaleViewData
    {
        $view = $this->sale->handle(new ViewSale($saleId));

        return new SaleViewData(self::same(SaleRowData::class, $view->sale), array_map(static fn (object $view): SaleSizeData => self::same(SaleSizeData::class, $view), $view->sizes));
    }

    /** P2's product filter: the products with offers in the store. */
    public function offerProducts(string $storeId, ?string $search, ?string $after): ProductResultsData
    {
        return $this->products($this->saleProducts->handle(new ListSaleProducts($storeId, $search, $after)));
    }

    /** The offer dialog's product search. */
    public function saleProducts(string $storeId, ?string $search, ?string $after): ProductResultsData
    {
        return $this->products($this->findProducts->handle(new FindSaleProducts($storeId, $search, $after)));
    }

    /**
     * @return list<SaleSizeChoiceData>
     */
    public function saleSizes(string $storeId, string $productId): array
    {
        return array_map(static fn (SaleSizeChoice $choice): SaleSizeChoiceData => self::same(SaleSizeChoiceData::class, $choice), $this->saleSizes->handle(new SaleSizeChoices($storeId, $productId)));
    }

    /**
     * @param  list<string>  $variantIds
     */
    public function note(string $storeId, ?string $productId, array $variantIds, ?string $startsAt, ?string $endsAt, ?string $saleId, ?string $percent, ?string $price, bool $alwaysWins): OverlapNoteData
    {
        $note = $this->overlaps->handle(new OverlapsFor($storeId, $productId, $variantIds, $startsAt, $endsAt, $saleId, $percent, $price, $alwaysWins));

        return new OverlapNoteData(
            array_map(self::overlapSale(...), $note->sales),
            array_map(self::overlapDiscount(...), $note->discounts),
            array_map(static fn (object $view): OverlapSizeData => self::same(OverlapSizeData::class, $view), $note->sizes),
            $note->atStart,
            $note->ceilingPercent,
        );
    }

    public function discounts(?string $code, ?string $state, string $sort, ?string $after): DiscountsPage
    {
        $store = $this->store($code);
        $list = $this->discounts->handle(new ListCategoryDiscounts($store->id, $state, $sort, $after));

        return new DiscountsPage(
            $this->frame($store),
            $store->timezone,
            $state ?? '',
            $sort,
            array_map(static fn (object $view): DiscountRowData => self::same(DiscountRowData::class, $view), $list->discounts),
            $list->next,
            $list->total,
        );
    }

    /**
     * A discount's page, in its own store - whoever may read that store's discounts (`ViewCategoryDiscount`
     * asks first).
     */
    public function discountPage(string $discountId, ?string $after): CategoryDiscountPage
    {
        $view = $this->discount->handle(new ViewCategoryDiscount($discountId, $after));
        $store = $this->storeById($view->storeId);

        return new CategoryDiscountPage($this->frame($store), $store->timezone, self::same(DiscountRowData::class, $view->discount), self::previewData($view->preview));
    }

    /**
     * @param  list<string>  $leftOut
     */
    public function preview(string $storeId, string $categoryId, ?string $percent, ?string $amount, string $lines, array $leftOut, ?string $startsAt, ?string $endsAt, ?string $discountId, ?string $after): DiscountPreviewData
    {
        return self::previewData($this->preview->handle(new PreviewCategoryDiscount($storeId, $categoryId, $percent, $amount, $lines, $leftOut, $startsAt, $endsAt, $discountId, $after)));
    }

    /**
     * @return list<CategoryChoiceData>
     */
    public function categories(string $storeId): array
    {
        return array_map(static fn (CategoryChoice $choice): CategoryChoiceData => new CategoryChoiceData($choice->id, $choice->path), $this->categories->handle(new CategoryChoices($storeId)));
    }

    public function leftOut(string $storeId, string $categoryId, ?string $search, ?string $after): ProductResultsData
    {
        return $this->products($this->leftOut->handle(new FindLeftOutProducts($storeId, $categoryId, $search, $after)));
    }

    public function wholesale(?string $code, ?string $search, ?string $after): WholesalePage
    {
        $store = $this->store($code);
        $list = $this->wholesale->handle(new ListWholesale($store->id, $search, $after));
        $thumbs = $this->thumbs(array_map(static fn (WholesaleRow $row): ?string => $row->size->photoMediaId, $list->rows));

        return new WholesalePage(
            $this->frame($store),
            $store->timezone,
            $search ?? '',
            array_map(fn (WholesaleRow $row): WholesaleRowData => new WholesaleRowData(
                $this->sizeData($row->size, $thumbs),
                $row->retail === null ? null : self::same(RetailPriceData::class, $row->retail),
                array_map(static fn (object $view): BandData => self::same(BandData::class, $view), $row->bands),
                $row->minimum,
                $row->maximum,
                $row->keptNotSoldWholesale,
                $row->noRetailPrice,
                $row->minimumChanged,
            ), $list->rows),
            $list->next,
            $list->total,
        );
    }

    public function wholesaleSizes(string $storeId, ?string $search, ?string $after): SizeResultsData
    {
        $found = $this->wholesaleSizes->handle(new FindWholesaleSizes($storeId, $search, $after));
        $thumbs = $this->thumbs(array_map(static fn (SizeView $size): ?string => $size->photoMediaId, $found->rows));

        return new SizeResultsData(array_map(fn (SizeView $size): SizeData => $this->sizeData($size, $thumbs), $found->rows), $found->next, $found->total);
    }

    public function needsAPrice(?string $code, ?string $search, ?string $after): NeedsAPricePage
    {
        $jobs = [PricingPermissions::PRICE_EDIT];
        $store = $this->store($code, $jobs);
        $list = $this->needsAPrice->handle(new NeedsAPrice($store->id, $search, $after));
        $thumbs = $this->thumbs(array_map(static fn (SizeView $size): ?string => $size->photoMediaId, $list->rows));

        return new NeedsAPricePage(
            $this->frame($store, $jobs),
            $store->timezone,
            $search ?? '',
            array_map(fn (SizeView $size): SizeData => $this->sizeData($size, $thumbs), $list->rows),
            $list->next,
            $list->total,
        );
    }

    /**
     * A running offer as its change sends it back with a new end (Change End): its own price or percentage
     * and "always wins", written as typed in its store's decimals - the command changes nothing else of a
     * running one, and refuses anything else changed (§4.1).
     *
     * @return array{percent: ?string, price: ?string, alwaysWins: bool}
     */
    public function saleAsTyped(string $saleId): array
    {
        $view = $this->sale->handle(new ViewSale($saleId));
        $sale = $view->sale;

        return [
            'percent' => $sale->percentBasisPoints === null ? null : self::typed($sale->percentBasisPoints, 2),
            'price' => $sale->salePrice === null ? null : self::typed($sale->salePrice->minor, $this->storeById($view->storeId)->currencyExponent),
            'alwaysWins' => $sale->alwaysWins,
        ];
    }

    /**
     * A running category discount as its change sends it back with a new end (Change End).
     *
     * @return array{categoryId: string, percent: ?string, amount: ?string, lines: string, alwaysWins: bool, leftOut: list<string>}
     */
    public function discountAsTyped(string $discountId): array
    {
        $view = $this->discount->handle(new ViewCategoryDiscount($discountId));
        $discount = $view->discount;

        return [
            'categoryId' => $discount->categoryId,
            'percent' => $discount->percentBasisPoints === null ? null : self::typed($discount->percentBasisPoints, 2),
            'amount' => $discount->amountOff === null ? null : self::typed($discount->amountOff->minor, $this->storeById($view->storeId)->currencyExponent),
            'lines' => $discount->lines,
            'alwaysWins' => $discount->alwaysWins,
            'leftOut' => $discount->leftOut,
        ];
    }

    /** A whole number of the smallest unit written with its decimals, as a person types it: 14950, 2 → "149.50". */
    private static function typed(int $minor, int $decimals): string
    {
        if ($decimals <= 0) {
            return (string) $minor;
        }

        $digits = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }

    /**
     * The store of a record the reader already read (a discount's): among the stores they may choose here.
     *
     * @throws Unauthorized
     */
    private function storeById(string $storeId): StoreDto
    {
        foreach ($this->choices->forJobs(...ReadAccess::ANY_OF_THE_THREE) as $store) {
            if (strtolower($store->id) === strtolower($storeId)) {
                return $store;
            }
        }

        throw new Unauthorized(PricingPermissions::PRICE_EDIT);
    }

    private function products(FoundProductsDto $found): ProductResultsData
    {
        $thumbs = $this->thumbs(array_map(static fn (FoundProductDto $row): ?string => $row->photoMediaId, $found->rows));

        return new ProductResultsData(
            array_map(static fn (FoundProductDto $row): PickedProductData => new PickedProductData($row->productId, $row->nameAr, $row->nameEn, $row->photoMediaId === null ? null : ($thumbs[strtolower($row->photoMediaId)] ?? null), $row->codes), $found->rows),
            $found->next,
            $found->total,
        );
    }

    /**
     * @param  array<string, string>  $thumbs
     */
    private function sizeData(SizeView $size, array $thumbs): SizeData
    {
        return new SizeData(
            $size->variantId,
            $size->productId,
            $size->productNameAr,
            $size->productNameEn,
            $size->photoMediaId === null ? null : ($thumbs[strtolower($size->photoMediaId)] ?? null),
            $size->code,
            $size->values,
            $size->archived,
            $size->switchedOn,
        );
    }

    /**
     * A page's data made from a read's answer of the same shape: each of its fields by name. The reads'
     * answers are plain objects (tests/Architecture/DtoStyleTest), the pages' data laravel-data's - the same
     * fields, a field that is an amount the same `MoneyData`.
     *
     * @template T of Data
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function same(string $class, object $view): Data
    {
        return new $class(...get_object_vars($view));
    }

    private static function previewProduct(PreviewProduct $product): PreviewProductData
    {
        return new PreviewProductData(
            $product->productId,
            $product->nameAr,
            $product->nameEn,
            array_map(static fn (PreviewSize $size): PreviewSizeData => self::same(PreviewSizeData::class, $size), $product->sizes),
        );
    }

    private static function previewData(DiscountPreview $preview): DiscountPreviewData
    {
        return new DiscountPreviewData(
            array_map(self::previewProduct(...), $preview->reached),
            $preview->next,
            $preview->reachedProducts,
            $preview->reachedSizes,
            $preview->skippedSizes,
            $preview->unpricedSizes,
            $preview->deepestBasisPoints,
            array_map(self::previewProduct(...), $preview->leftOut),
            array_map(self::overlapSale(...), $preview->overlappingSales),
            array_map(self::overlapDiscount(...), $preview->overlappingDiscounts),
        );
    }

    private static function overlapSale(OverlappingSale $sale): OverlapOfferData
    {
        return new OverlapOfferData($sale->id, $sale->description ?? '', $sale->startsAt, $sale->endsAt, $sale->alwaysWins);
    }

    private static function overlapDiscount(OverlappingDiscount $discount): OverlapOfferData
    {
        return new OverlapOfferData($discount->id, $discount->description ?? '', $discount->startsAt, $discount->endsAt, $discount->alwaysWins);
    }

    /**
     * The rows' photos as thumbnails, in one read (as Catalog's lists do).
     *
     * @param  list<string|null>  $mediaIds
     * @return array<string, string> media id => its thumbnail's address
     */
    private function thumbs(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter($mediaIds, static fn (?string $id): bool => $id !== null && $id !== '')));
        $thumbs = [];

        foreach ($ids === [] ? [] : $this->platform->mediaUrlsOf($ids) as $id => $urls) {
            $thumb = self::thumb($urls);

            if ($thumb !== null) {
                $thumbs[strtolower((string) $id)] = $thumb;
            }
        }

        return $thumbs;
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

    /**
     * @return 'ar'|'en'
     */
    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
