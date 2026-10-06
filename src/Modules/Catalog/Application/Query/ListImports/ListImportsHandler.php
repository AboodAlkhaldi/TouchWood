<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListImports;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\Imports;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The products files uploaded** (catalog.md §1.12): `catalog.import.run`, newest first, a page at a
 * time — each file keeps its page as the record of what came from it.
 */
final readonly class ListImportsHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public const int PER_PAGE_MAX = 100;

    /** A page past this is nobody's, and a larger one overflows the offset. */
    private const int PAGE_MAX = 100_000;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListImports $query): ImportList
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $page = min(max($query->page, 1), self::PAGE_MAX);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);
        [$imports, $total] = $this->imports->summaries(ImportHeader::PRODUCTS, null, $page, $perPage);

        return new ImportList($imports, $total, $page, $perPage);
    }
}
