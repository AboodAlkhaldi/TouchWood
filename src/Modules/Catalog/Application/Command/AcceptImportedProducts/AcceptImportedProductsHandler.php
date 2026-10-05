<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AcceptImportedProducts;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Import\BroughtInProducts;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * **Accepting an import's products** (catalog.md §1.12, page part 4; amendment 6(f)):
 * `catalog.import.run`. Each brought in — created, updated or replaced, not accepted yet — is **made
 * ready** ("ready to publish, not published") if it is a draft, **switched on in the stores the file
 * named for it** — every store of the panel's, on or off, so one being prepared is filled too —, and
 * given the related and goes-with products whose codes it named, among those ready once all are
 * accepted (amendment 3(d)). Through the product handlers, which check and audit as for a person.
 *
 * Chosen by name, one that cannot be accepted is named and nothing changes; "every one ready" leaves
 * the rest as they are.
 */
final readonly class AcceptImportedProductsHandler
{
    public const string PERMISSION = BroughtInProducts::PERMISSION;

    private const array ACCEPTABLE = ['IN', 'UPDATED', 'REPLACED'];

    public function __construct(
        private BroughtInProducts $rows,
        private ProductRepository $products,
        private Readiness $readiness,
        private PlatformApi $platform,
        private MarkProductReadyHandler $markReady,
        private ChooseInStoreHandler $choose,
        private SetRelationsHandler $relations,
    ) {}

    /**
     * @return int how many products it accepted
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(AcceptImportedProducts $command): int
    {
        $this->rows->authorize();
        $stores = [];

        foreach ($this->platform->allStores() as $store) {
            $stores[$store->code] = $store;
        }

        return $this->rows->run(
            $command->importId,
            $command->productIds,
            fn (ImportProduct $row, bool $all): ?string => $this->accept($row, $all, $stores) ? 'ACCEPTED' : null,
            static fn (string $importId, int $count): ?AuditEntryDto => ListAudit::changed('import', 'accepted', $importId, ['products' => null], ['products' => $count]),
            // Once all are ready, so products accepted together may be related to each other.
            function (array $accepted, array $rows): void {
                foreach ($accepted as $row) {
                    $this->relate($row);
                }

                // Products accepted before that named these ones gain them, now that they are ready.
                $ready = array_values(array_filter(array_map(static fn (ImportProduct $row): ?string => $row->productId, $accepted)));

                foreach ($rows as $row) {
                    if ($row->state === 'ACCEPTED' && $row->productId !== null) {
                        $this->linkBack($row, $ready);
                    }
                }
            },
        );
    }

    /**
     * @param  array<string, StoreDto>  $stores  code => store
     */
    private function accept(ImportProduct $row, bool $all, array $stores): bool
    {
        $at = "product {$row->number}";

        if (! in_array($row->state, self::ACCEPTABLE, true) || $row->productId === null) {
            return $all ? false : throw new InvalidCatalogAttribute($at, 'a product brought in and not accepted yet');
        }

        $product = $this->products->find($row->productId);

        if ($product === null || $product->stage() === ProductStage::Archived) {
            return $all ? false : throw new InvalidCatalogAttribute($at, 'a product not archived');
        }

        if ($product->isDraft()) {
            $missing = $this->readiness->missing($product);

            if ($missing !== []) {
                return $all ? false : throw new InvalidCatalogAttribute($at, 'ready to accept: it lacks '.implode(', ', $missing));
            }

            $this->markReady->handle(new MarkProductReady($product->id()));
        }

        foreach (array_keys($row->effective()->stores) as $code) {
            if (isset($stores[$code])) {
                $this->choose->handle(new ChooseInStore($stores[$code]->id, $product->id(), true));
            }
        }

        return true;
    }

    /**
     * An earlier accepted product's related and goes-with products, with those just made ready that
     * its file named — added to what it has, never taking any away.
     *
     * @param  list<string>  $ready  the products just accepted
     */
    private function linkBack(ImportProduct $row, array $ready): void
    {
        $file = $row->effective();
        $productId = (string) $row->productId;

        foreach (['RELATED' => $file->related, 'GOES_WITH' => $file->goesWith] as $kind => $codes) {
            $added = array_values(array_intersect($this->readyHolders($codes, $productId), $ready));
            $has = $this->products->relations($productId, $kind);

            if (array_diff($added, $has) !== []) {
                $this->relations->handle(new SetRelations($productId, $kind, array_slice(array_values(array_unique([...$has, ...$added])), 0, ProductParts::MAX_RELATIONS)));
            }
        }
    }

    /** The related and goes-with products whose codes the file named, among those ready. */
    private function relate(ImportProduct $row): void
    {
        $file = $row->effective();

        foreach (['RELATED' => $file->related, 'GOES_WITH' => $file->goesWith] as $kind => $codes) {
            $related = $this->readyHolders($codes, (string) $row->productId);

            if ($related !== []) {
                $this->relations->handle(new SetRelations((string) $row->productId, $kind, $related));
            }
        }
    }

    /**
     * The ready products holding these codes, each once, never the product itself.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function readyHolders(array $codes, string $productId): array
    {
        $ids = [];

        foreach ($codes as $code) {
            $holder = $this->products->codeHolder($code);

            if ($holder !== null && $holder !== $productId && $this->products->find($holder)?->stage() === ProductStage::Ready) {
                $ids[$holder] = true;
            }
        }

        return array_slice(array_keys($ids), 0, ProductParts::MAX_RELATIONS);
    }
}
