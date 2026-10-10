<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewStoreFill;

use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\InMemoryImportSections;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Contracts\ImportSection;
use Modules\Catalog\Public\Enums\ProductStage;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A store file's page** (catalog.md §1.3; amendment 6(g)): `catalog.listing.fill` in the file's
 * store, asked as every change to the file asks it (`StoreFills`) — a file of a store not covered is
 * "not found". Each open item read against the catalog as it is now: unknown, not ready (and what its
 * product lacks), archived, already on there, or ready.
 */
final readonly class ViewStoreFillHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public function __construct(
        private StoreFills $fills,
        private Imports $imports,
        private ProductRepository $products,
        private VariantRepository $variants,
        private StoreListingRepository $listings,
        private Readiness $readiness,
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

        return new StoreFillView(
            $import->id,
            $store,
            $import->fileName,
            (string) $import->uploadedAt,
            array_map(fn (StoreFillItem $item): StoreFillItemView => $this->item($item, $store, $sections), $this->imports->items($import->id)),
            $sections !== [],
        );
    }

    /**
     * @param  list<ImportSection>  $sections
     */
    private function item(StoreFillItem $item, string $store, array $sections): StoreFillItemView
    {
        if ($item->state !== StoreFillItem::OPEN) {
            return new StoreFillItemView($item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, null, null, []);
        }

        $lines = [];

        foreach ($sections as $section) {
            array_push($lines, ...$section->lines(StoreId::fromString($store), $item->price, $item->stock));
        }

        $holder = $this->products->codeHolder($item->code);
        $product = $holder === null ? null : $this->products->find($holder);
        [$standing, $missing] = match (true) {
            $product === null => [StoreFillItemView::UNKNOWN, []],
            $product->stage() === ProductStage::Archived => [StoreFillItemView::ARCHIVED, []],
            $product->stage() === ProductStage::Draft => [StoreFillItemView::NOT_READY, $this->readiness->missing($product)],
            default => [$this->standing($product->id(), $item->code, $store), []],
        };

        return new StoreFillItemView($item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, $standing, $product?->id(), $missing, $lines);
    }

    /**
     * A ready product's item, as switching it on would find it: the variant carrying the code now
     * (amendment 16(a)) — none, when the product held it once (`UNKNOWN`); archived (`ARCHIVED`); on
     * there already (`ALREADY_ON`); or to switch on (`READY`).
     */
    private function standing(string $productId, string $code, string $store): string
    {
        $variant = $this->variants->carrying($code);

        return match (true) {
            $variant === null => StoreFillItemView::UNKNOWN,
            $variant->isArchived() => StoreFillItemView::ARCHIVED,
            in_array($variant->id(), $this->listings->of($store, $productId)->activeVariantIds(), true) => StoreFillItemView::ALREADY_ON,
            default => StoreFillItemView::READY,
        };
    }
}
