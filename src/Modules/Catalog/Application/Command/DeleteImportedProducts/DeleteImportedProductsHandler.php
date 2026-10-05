<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteImportedProducts;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Import\BroughtInProducts;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Deleting an import's products nobody wants** (catalog.md §1.12, page part 4; amendment 6(e)):
 * `catalog.import.run`. Only the products this import created and nobody accepted yet — never one it
 * updated or replaced, which the catalog had before: that one is changed in its own page. Each is
 * **deleted** — a draft, never shown: gone whole, its codes and addresses free again (§4.1), through the product handler, which checks and audits as for a person.
 */
final readonly class DeleteImportedProductsHandler
{
    public const string PERMISSION = BroughtInProducts::PERMISSION;

    public function __construct(
        private BroughtInProducts $rows,
        private DeleteDraftProductHandler $delete,
    ) {}

    /**
     * @return int how many products it deleted
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(DeleteImportedProducts $command): int
    {
        $this->rows->authorize();

        return $this->rows->run($command->importId, $command->productIds, function (ImportProduct $row): string {
            if ($row->state !== 'IN' || $row->productId === null) {
                throw new InvalidCatalogAttribute("product {$row->number}", 'a product this import created and nobody accepted yet');
            }

            $this->delete->handle(new DeleteDraftProduct($row->productId));

            return 'DELETED';
        }, static fn (string $importId, int $count): ?AuditEntryDto => ListAudit::changed('import', 'deleted', $importId, ['products' => null], ['products' => $count]));
    }
}
