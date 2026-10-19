<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Application\Query\AddSizeChoices\AddSizeChoices;
use Modules\Inventory\Application\Query\AddSizeChoices\AddSizeChoicesHandler;
use Modules\Inventory\Application\Query\InventoryActions\InventoryActionsForReader;
use Modules\Inventory\Application\Query\ListStock\ListStock;
use Modules\Inventory\Application\Query\ListStock\ListStockHandler;
use Modules\Inventory\Application\Query\ListStock\StockListRow;
use Modules\Inventory\Application\Query\LowStock\LowStock;
use Modules\Inventory\Application\Query\LowStock\LowStockHandler;
use Modules\Inventory\Application\Query\LowStock\LowStockRow;
use Modules\Inventory\Application\Query\StockHistory\HistoryLine;
use Modules\Inventory\Application\Query\StockHistory\StockHistory;
use Modules\Inventory\Application\Query\StockHistory\StockHistoryHandler;
use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\ViewStock\SizeStockView;
use Modules\Inventory\Application\Query\ViewStock\ViewStock;
use Modules\Inventory\Application\Query\ViewStock\ViewStockHandler;
use Modules\Inventory\Application\Stock\StockSaved;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Spatie\LaravelData\Data;

/**
 * Inventory's screens' data, in the shape the pages want (inventory.md §4.3, stage 5's screens step 5).
 *
 * It decides nothing: what the reader may read and do is Inventory's answer - the reads, and
 * `InventoryActionsForReader` - and which store a page shows is Platform's `StoreChoices`, as on every store
 * screen (frontend.md §2.2): the stores where the reader manages stock, a Super Admin's off ones too; the
 * first that is on when none is asked; any other refused (403). What happens here is shaping: the store's
 * code into its id, the rows' photos into thumbnails (one read a page), the reads' answers into the pages'
 * data, and the sizes a save left short into their amber toast.
 */
final class InventoryPages
{
    /**
     * The sizes' drawers already read in this request, by "store size".
     *
     * @var array<string, SizeStockView>
     */
    private array $views = [];

    /**
     * The thumbnails already read in this request, by media id (null: none): Stock opened with its drawer
     * reads the drawer's photo with the rows', not again.
     *
     * @var array<string, string|null>
     */
    private array $photos = [];

    public function __construct(
        private readonly Application $app,
        private readonly StoreChoices $choices,
        private readonly PlatformApi $platform,
        private readonly CatalogApi $catalog,
        private readonly InventoryActionsForReader $actions,
        private readonly ListStockHandler $list,
        private readonly ViewStockHandler $view,
        private readonly StockHistoryHandler $history,
        private readonly LowStockHandler $low,
        private readonly AddSizeChoicesHandler $addSizes,
    ) {}

    /**
     * The store a screen shows, by the code in its address - the first that is on when none is asked.
     *
     * @throws Unauthorized when it is not one of the reader's, or the reader has none
     */
    public function store(?string $code): StoreDto
    {
        return $this->choices->chosen($code, InventoryPermissions::STOCK_MANAGE) ?? throw new Unauthorized(InventoryPermissions::STOCK_MANAGE);
    }

    /**
     * The store an action names - always named: a form that names none is refused, never sent to the first
     * store. The handler then asks for its own job there.
     *
     * @throws Unauthorized
     */
    public function storeId(?string $code): string
    {
        if ($code === null || trim($code) === '') {
            throw new Unauthorized(InventoryPermissions::STOCK_MANAGE);
        }

        return $this->store($code)->id;
    }

    /**
     * @throws Unauthorized
     */
    public function frame(StoreDto $store): InventoryFrameData
    {
        $actions = $this->actions->forStore($store->id) ?? throw new Unauthorized(InventoryPermissions::STOCK_MANAGE);
        $reasons = $this->actions->reasons();
        $locale = $this->locale();

        return new InventoryFrameData(
            $store->code,
            $store->name->in($locale),
            array_map(static fn (StoreDto $each): InventoryStoreOptionData => new InventoryStoreOptionData($each->code, $each->name->in($locale), $each->isActive), $this->choices->forJobs(InventoryPermissions::STOCK_MANAGE)),
            $actions->off,
            $actions->wired,
            $actions->mayViewProducts,
            (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store->storeId())->value,
            new InventoryReasonsData($reasons->wired, $reasons->archived),
        );
    }

    /**
     * Stock's rows - with the drawer's size, when the address names one, read first so its photo comes in the
     * rows' one read (the page and its open drawer within the budget of 15).
     */
    public function stock(?string $code, ?string $search, string $filter, ?string $after, ?string $drawerSize = null): StockPage
    {
        $store = $this->store($code);
        $drawerPhoto = null;

        if ($drawerSize !== null) {
            try {
                $drawerPhoto = $this->sizeView($store->id, $drawerSize)->size->photoMediaId;
            } catch (DomainError) {
                // The drawer says its own refusal; the rows are drawn regardless.
            }
        }

        $list = $this->list->handle(new ListStock($store->id, $search, $filter, $after));
        $thumbs = $this->thumbs([...array_map(static fn (StockListRow $row): ?string => $row->size->photoMediaId, $list->rows), $drawerPhoto]);

        return new StockPage(
            $this->frame($store),
            $store->timezone,
            $search ?? '',
            $filter,
            array_map(fn (StockListRow $row): StockRowData => new StockRowData($this->sizeData($row->size, $thumbs), self::same(StockFiguresData::class, $row->figures), $row->lastPieces), $list->rows),
            $list->next,
            $list->total,
        );
    }

    /** A size's drawer: its figures, its product's Last Pieces, its holds. */
    public function sizeStock(string $storeId, string $variantId): SizeStockData
    {
        $view = $this->sizeView($storeId, $variantId);

        return new SizeStockData(
            $this->sizeData($view->size, $this->thumbs([$view->size->photoMediaId])),
            self::same(StockFiguresData::class, $view->figures),
            $view->lastPieces,
            self::same(HoldsData::class, $view->holds),
        );
    }

    /** A size's drawer as Inventory reads it, once a request. */
    private function sizeView(string $storeId, string $variantId): SizeStockView
    {
        $key = strtolower("{$storeId} {$variantId}");

        return $this->views[$key] ??= $this->view->handle(new ViewStock($storeId, $variantId));
    }

    public function history(string $storeId, string $variantId, ?string $after): HistoryData
    {
        $page = $this->history->handle(new StockHistory($storeId, $variantId, $after));

        return new HistoryData(array_map(static fn (HistoryLine $line): HistoryLineData => self::same(HistoryLineData::class, $line), $page->lines), $page->next);
    }

    public function lowStock(?string $code, ?string $after): LowStockPage
    {
        $store = $this->store($code);
        $list = $this->low->handle(new LowStock($store->id, $after));
        $thumbs = $this->thumbs(array_map(static fn (LowStockRow $row): ?string => $row->size->photoMediaId, $list->rows));

        return new LowStockPage(
            $this->frame($store),
            $store->timezone,
            array_map(fn (LowStockRow $row): StockRowData => new StockRowData($this->sizeData($row->size, $thumbs), self::same(StockFiguresData::class, $row->figures), null), $list->rows),
            $list->next,
            $list->total,
        );
    }

    public function addSizes(string $storeId, ?string $search, ?string $after): SizeChoicesData
    {
        $found = $this->addSizes->handle(new AddSizeChoices($storeId, $search, $after));
        $thumbs = $this->thumbs(array_map(static fn (SizeView $size): ?string => $size->photoMediaId, [...$found->rows, ...$found->alreadyListed]));

        return new SizeChoicesData(
            array_map(fn (SizeView $size): StockSizeData => $this->sizeData($size, $thumbs), $found->rows),
            $found->next,
            $found->total,
            array_map(fn (SizeView $size): StockSizeData => $this->sizeData($size, $thumbs), $found->alreadyListed),
        );
    }

    /**
     * The amber toast of a save that left orders short (inventory.md §4.3.1, Q20): "2 orders are short of
     * 60 cm · Oak", a sentence a size, the sizes named in the page's language - one read of Catalog. None
     * when nothing is short.
     */
    public function shortWarning(StockSaved $saved): ?string
    {
        if ($saved->short === []) {
            return null;
        }

        $locale = $this->locale();
        $variants = $this->catalog->variants(array_map(static fn ($short): string => $short->variantId, $saved->short));
        $lines = [];

        foreach ($saved->short as $short) {
            $values = $variants[$short->variantId]->values ?? [];
            $name = $values === [] ? (string) __('inventory::admin_stock.one_size') : implode(' · ', array_map(static fn (VariantValueDto $value): string => $value->value->in($locale), $values));
            $lines[] = trans_choice('inventory::admin_stock.toast.short', $short->orders, ['count' => (string) $short->orders, 'size' => $name]);
        }

        return implode(' ', $lines);
    }

    /**
     * A page's data made from a read's answer of the same shape: each of its fields by name.
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

    /**
     * @param  array<string, string>  $thumbs
     */
    private function sizeData(SizeView $size, array $thumbs): StockSizeData
    {
        return new StockSizeData(
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
     * The rows' photos as thumbnails, in one read (as Catalog's and Pricing's lists do).
     *
     * @param  list<string|null>  $mediaIds
     * @return array<string, string> media id => its thumbnail's address
     */
    private function thumbs(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('strtolower', array_filter($mediaIds, static fn (?string $id): bool => $id !== null && $id !== ''))));
        $unread = array_values(array_filter($ids, fn (string $id): bool => ! array_key_exists($id, $this->photos)));

        foreach ($unread as $id) {
            $this->photos[$id] = null;
        }

        foreach ($unread === [] ? [] : $this->platform->mediaUrlsOf($unread) as $id => $urls) {
            $this->photos[strtolower((string) $id)] = self::thumb($urls);
        }

        $thumbs = [];

        foreach ($ids as $id) {
            $thumb = $this->photos[$id] ?? null;

            if ($thumb !== null) {
                $thumbs[$id] = $thumb;
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
