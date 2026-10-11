<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportSummary;
use Modules\Catalog\Application\Query\ListStoreFills\ListStoreFills;
use Modules\Catalog\Application\Query\ListStoreFills\ListStoreFillsHandler;
use Modules\Catalog\Application\Query\ViewStoreFill\StoreFillItemView;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFill;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFillHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store files' pages (catalog.md §4.4 S10) from Catalog's reads, which ask for
 * `catalog.listing.fill` in the store themselves; the store a list shows is chosen among those where
 * the reader holds it (`StoreChoices`) - none asked for, the first that is on.
 */
final readonly class StoreFilePages
{
    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
        private ListStoreFillsHandler $list,
        private ViewStoreFillHandler $view,
    ) {}

    /**
     * @param  list<array{at: string, problem: string}>|null  $problems  the last upload's, refused
     *
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function files(?string $storeCode, int $page, ?array $problems): StoreFilesPage
    {
        // The stores offered, read once: the one shown is chosen among them, as StoreChoices::chosen()
        // chooses, without reading them again.
        $offered = $this->choices->forJobs(CatalogPermissions::LISTING_FILL);
        $chosen = $storeCode === null ? self::firstOn($offered) : self::storeIn($offered, $storeCode);
        $list = $this->list->handle(new ListStoreFills($chosen->id, $page));

        return new StoreFilesPage(
            array_map(static fn (ImportSummary $file): StoreFileRowData => new StoreFileRowData($file->id, $file->fileName, $file->uploadedAt, $file->count, $file->open ?? 0), $list->imports),
            $list->page,
            $list->page * $list->perPage < $list->total,
            $list->total,
            $chosen->code,
            $chosen->name->in($this->locale()),
            $chosen->timezone,
            array_map(fn (StoreDto $store): StoreOptionData => new StoreOptionData($store->id, $store->code, $store->name->in($this->locale()), $store->isActive), $offered),
            $problems === null ? null : array_map(static fn (array $problem): ImportProblemData => new ImportProblemData($problem['at'], $problem['problem']), $problems),
        );
    }

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function file(string $importId): StoreFilePage
    {
        $view = $this->view->handle(new ViewStoreFill($importId));
        $store = $this->platform->store(StoreId::fromString($view->storeId)) ?? throw new ListItemNotFound($importId);

        return new StoreFilePage(
            $view->id,
            $view->fileName,
            $view->uploadedAt,
            $store->code,
            $store->name->in($this->locale()),
            $store->timezone,
            $view->pricesKept,
            array_map(static fn (StoreFillItemView $item): StoreFileItemData => new StoreFileItemData(
                $item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, $item->standing,
                $item->productId, $item->productNameAr, $item->productNameEn, $item->missing, $item->lines,
            ), $view->items),
        );
    }

    /**
     * The first store that is on: nobody lands in an off store without choosing it (StoreChoices).
     *
     * @param  list<StoreDto>  $stores
     *
     * @throws Unauthorized when there is none
     */
    private static function firstOn(array $stores): StoreDto
    {
        foreach ($stores as $store) {
            if ($store->isActive) {
                return $store;
            }
        }

        // A Super Admin with every store off still opens one.
        return $stores[0] ?? throw new Unauthorized(CatalogPermissions::LISTING_FILL);
    }

    /**
     * The store asked for in the page's filter, among those offered; another, or one that does not
     * exist, is the same refusal, so a filter tells nobody which stores there are (StoreChoices).
     *
     * @param  list<StoreDto>  $stores
     *
     * @throws Unauthorized
     */
    private static function storeIn(array $stores, string $code): StoreDto
    {
        foreach ($stores as $store) {
            if (strtolower($store->code) === strtolower(trim($code))) {
                return $store;
            }
        }

        throw new Unauthorized(CatalogPermissions::LISTING_FILL);
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
