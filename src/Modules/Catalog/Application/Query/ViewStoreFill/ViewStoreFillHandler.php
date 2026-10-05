<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewStoreFill;

use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Shared\Application\Unauthorized;

/**
 * **A store file's page** (catalog.md §1.3; amendment 6(g)): `catalog.listing.fill` in the file's
 * store. Each open item read against the catalog as it is now: unknown, not ready (and what its
 * product lacks), archived, already on there, or ready.
 */
final readonly class ViewStoreFillHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public function __construct(
        private StoreListingChange $change,
        private Imports $imports,
        private ProductRepository $products,
        private VariantRepository $variants,
        private StoreListingRepository $listings,
        private Readiness $readiness,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(ViewStoreFill $query): StoreFillView
    {
        $import = $this->imports->header($query->importId);

        if ($import === null || $import->kind !== ImportHeader::STORE_FILL || $import->storeId === null) {
            throw new ListItemNotFound($query->importId);
        }

        $store = $this->change->authorize(self::PERMISSION, $import->storeId)->value;

        return new StoreFillView(
            $import->id,
            $store,
            $import->fileName,
            $import->uploadedBy,
            (string) $import->uploadedAt,
            array_map(fn (StoreFillItem $item): StoreFillItemView => $this->item($item, $store), $this->imports->items($import->id)),
            false,
        );
    }

    private function item(StoreFillItem $item, string $store): StoreFillItemView
    {
        if ($item->state !== StoreFillItem::OPEN) {
            return new StoreFillItemView($item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, null, null, []);
        }

        $holder = $this->products->codeHolder($item->code);
        $product = $holder === null ? null : $this->products->find($holder);
        [$standing, $missing] = match (true) {
            $product === null => [StoreFillItemView::UNKNOWN, []],
            $product->stage() === ProductStage::Archived => [StoreFillItemView::ARCHIVED, []],
            $product->stage() === ProductStage::Draft => [StoreFillItemView::NOT_READY, $this->readiness->missing($product)],
            default => [$this->onAlready($product->id(), $item->code, $store) ? StoreFillItemView::ALREADY_ON : StoreFillItemView::READY, []],
        };

        return new StoreFillItemView($item->id, $item->number, $item->code, $item->price, $item->stock, $item->state, $standing, $product?->id(), $missing);
    }

    /** Whether every variant carrying the code, not archived, is on in the store already. */
    private function onAlready(string $productId, string $code, string $store): bool
    {
        $carrying = array_map(
            static fn (Variant $variant): string => $variant->id(),
            array_filter($this->variants->ofProduct($productId), static fn (Variant $variant): bool => ! $variant->isArchived() && $variant->code()->value === $code),
        );

        return $carrying !== [] && array_diff($carrying, $this->listings->of($store, $productId)->activeVariantIds()) === [];
    }
}
