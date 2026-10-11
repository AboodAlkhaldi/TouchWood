<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewStoreFill;

use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\InMemoryImportSections;
use Modules\Catalog\Application\Import\StoreFillCode;
use Modules\Catalog\Application\Import\StoreFillCodes;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductCore;
use Modules\Catalog\Application\Query\Products\ProductMissing;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Public\Contracts\ImportSection;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A store file's page** (catalog.md §1.3; amendment 6(g)): `catalog.listing.fill` in the file's
 * store, asked as every change to the file asks it (`StoreFills`) — a file of a store not covered is
 * "not found". Each open item read against the catalog as it is now: unknown, not ready (and what its
 * product lacks), archived, already on there, or ready.
 *
 * **Read in batches** (P13): the items, then every open item's code in one query, their products in
 * one, their photos in one Platform read - never one an item, so a file at its 1,000 stays within the
 * panel's 15 queries (frontend.md §5). What a product lacks follows the product page's rules
 * (`ProductMissing`).
 */
final readonly class ViewStoreFillHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public function __construct(
        private StoreFills $fills,
        private Imports $imports,
        private StoreFillCodes $codes,
        private CatalogProductReads $products,
        private PlatformApi $platform,
        private InMemoryImportSections $sections,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(ViewStoreFill $query): StoreFillView
    {
        $store = $this->fills->authorize($query->importId);
        $import = $this->imports->header($query->importId) ?? throw new ListItemNotFound($query->importId);
        // The prices and stock are kept only once a module registers its part (§2.3): until then the
        // page says so, once.
        $sections = $this->sections->all();
        $items = $this->imports->items($import->id);
        $open = array_filter($items, static fn (StoreFillItem $item): bool => $item->state === StoreFillItem::OPEN);
        $codes = $this->codes->in($store, array_values(array_unique(array_map(static fn (StoreFillItem $item): string => $item->code, $open))));
        $products = $this->products->cores(array_values(array_unique(array_map(static fn (StoreFillCode $code): string => $code->productId, $codes))));
        $photoStates = $this->photoStates(array_filter($products, static fn (ProductCore $product): bool => $product->stage === ProductStage::Draft->value));

        return new StoreFillView(
            $import->id,
            $store,
            $import->fileName,
            (string) $import->uploadedAt,
            array_map(fn (StoreFillItem $item): StoreFillItemView => $this->item($item, $store, $sections, $codes, $products, $photoStates), $items),
            $sections !== [],
        );
    }

    /**
     * @param  list<ImportSection>  $sections
     * @param  array<string, StoreFillCode>  $codes
     * @param  array<string, ProductCore>  $products
     * @param  array<string, string>  $photoStates
     */
    private function item(StoreFillItem $item, string $store, array $sections, array $codes, array $products, array $photoStates): StoreFillItemView
    {
        if ($item->state !== StoreFillItem::OPEN) {
            return new StoreFillItemView($item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, null, null, []);
        }

        $lines = [];

        foreach ($sections as $section) {
            array_push($lines, ...$section->lines(StoreId::fromString($store), $item->price, $item->stock));
        }

        $code = $codes[$item->code] ?? null;
        $product = $code === null ? null : ($products[$code->productId] ?? null);
        [$standing, $missing] = match (true) {
            $code === null, $product === null => [StoreFillItemView::UNKNOWN, []],
            $product->stage === ProductStage::Archived->value => [StoreFillItemView::ARCHIVED, []],
            $product->stage === ProductStage::Draft->value => [StoreFillItemView::NOT_READY, ProductMissing::of($product, array_intersect_key($photoStates, array_flip($product->gallery)))],
            default => [self::standing($code), []],
        };

        return new StoreFillItemView($item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, $standing, $product?->id, $missing, $lines, $product?->nameAr, $product?->nameEn);
    }

    /**
     * A ready product's item, as switching it on would find it: the variant carrying the code now
     * (amendment 16(a)) — none, when the product held it once (`UNKNOWN`); archived (`ARCHIVED`); on
     * there already (`ALREADY_ON`); or to switch on (`READY`).
     */
    private static function standing(StoreFillCode $code): string
    {
        return match (true) {
            $code->variantId === null => StoreFillItemView::UNKNOWN,
            $code->variantArchived => StoreFillItemView::ARCHIVED,
            $code->onHere => StoreFillItemView::ALREADY_ON,
            default => StoreFillItemView::READY,
        };
    }

    /**
     * The drafts' gallery photos' states, in one Platform read.
     *
     * @param  array<string, ProductCore>  $drafts
     * @return array<string, string> media id => its state
     */
    private function photoStates(array $drafts): array
    {
        $mediaIds = [];

        foreach ($drafts as $draft) {
            $mediaIds = [...$mediaIds, ...$draft->gallery];
        }

        if ($mediaIds === []) {
            return [];
        }

        $states = [];

        foreach ($this->platform->mediaOf(array_values(array_unique($mediaIds))) as $mediaId => $media) {
            $states[$mediaId] = $media->variantsStatus->value ?? MediaVariantsStatus::Pending->value;
        }

        return $states;
    }
}
