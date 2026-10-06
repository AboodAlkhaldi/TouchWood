<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteImportedProducts;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Import\BroughtInProducts;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Products\ProductDeletion;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Deleting an import's products nobody wants** (catalog.md §1.12, page part 4; amendment 6(e)):
 * `catalog.import.run`. Only the products this import created and nobody accepted yet — never one it
 * updated or replaced, which the catalog had before: that one is changed in its own page. Each is
 * **deleted** — gone whole, its codes and addresses free again (§4.1): a draft through the product
 * handler, which checks and audits as for a person; **one made ready, or archived, since it came in**
 * as the Super Admin's word is carried out (owner, 2026-10-06, amendment 11(c)) — archived first, so
 * it is switched off in every store with each store's change audited, the other products' links to
 * it dropped through their own handler, then deleted whole.
 */
final readonly class DeleteImportedProductsHandler
{
    public const string PERMISSION = BroughtInProducts::PERMISSION;

    public function __construct(
        private BroughtInProducts $rows,
        private DeleteDraftProductHandler $delete,
        private ArchiveProductHandler $archive,
        private SetRelationsHandler $relations,
        private ProductRepository $products,
        private ProductDeletion $deletion,
        private PlatformApi $platform,
    ) {}

    /**
     * @return int how many products it deleted
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(DeleteImportedProducts $command): int
    {
        $this->rows->authorize();

        return $this->rows->run($command->importId, $command->productIds, function (ImportProduct $row, bool $all): ?string {
            // Every one: those the import did not create, or someone accepted, are passed by.
            if ($row->state !== 'IN' || $row->productId === null) {
                return $all ? null : throw new InvalidCatalogAttribute("product {$row->number}", 'a product this import created and nobody accepted yet');
            }

            $product = $this->products->byId($row->productId) ?? throw new ProductNotFound($row->productId);

            if ($product->isDraft()) {
                $this->delete->handle(new DeleteDraftProduct($row->productId));

                return 'DELETED';
            }

            $this->archive->handle(new ArchiveProduct($row->productId));

            foreach ($this->products->linkedFrom($row->productId) as $productId => $kinds) {
                foreach ($kinds as $kind) {
                    $this->relations->handle(new SetRelations($productId, $kind, array_values(array_diff($this->products->relations($productId, $kind), [$row->productId]))));
                }
            }

            // Read again as archiving left it, inside the same lock.
            foreach ($this->deletion->delete($this->products->find($row->productId) ?? throw new ProductNotFound($row->productId)) as $entry) {
                $this->platform->recordAudit($entry);
            }

            return 'DELETED';
        }, static fn (string $importId, int $count): ?AuditEntryDto => ListAudit::changed('import', 'deleted', $importId, ['products' => null], ['products' => $count]));
    }
}
