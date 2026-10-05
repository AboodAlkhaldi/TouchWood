<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ArchiveImportedProducts;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Import\BroughtInProducts;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Archiving an import's products nobody wants** (catalog.md §1.12, page part 4; amendment 6(e)):
 * `catalog.import.run`. Only the products this import created and nobody accepted yet — never one it
 * updated or replaced, which the catalog had before: that one is changed in its own page. Each is
 * **archived**: shown nowhere, restored whole later if wanted (§4.1), through the product handler, which checks and audits as for a person.
 */
final readonly class ArchiveImportedProductsHandler
{
    public const string PERMISSION = BroughtInProducts::PERMISSION;

    public function __construct(
        private BroughtInProducts $rows,
        private ArchiveProductHandler $archive,
    ) {}

    /**
     * @return int how many products it archived
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(ArchiveImportedProducts $command): int
    {
        $this->rows->authorize();

        return $this->rows->run($command->importId, $command->productIds, function (ImportProduct $row): string {
            if ($row->state !== 'IN' || $row->productId === null) {
                throw new InvalidCatalogAttribute("product {$row->number}", 'a product this import created and nobody accepted yet');
            }

            $this->archive->handle(new ArchiveProduct($row->productId));

            return 'ARCHIVED';
        }, static fn (string $importId, int $count): ?AuditEntryDto => ListAudit::changed('import', 'archived', $importId, ['products' => null], ['products' => $count]));
    }
}
