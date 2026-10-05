<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListStoreFills;

use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Application\Query\ListImports\ImportList;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Application\Unauthorized;

/**
 * **A store's files** (catalog.md §1.3; amendment 6(g)): `catalog.listing.fill` in that store, newest
 * first, a page at a time.
 */
final readonly class ListStoreFillsHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public const int PER_PAGE_MAX = 100;

    /** A page past this is nobody's, and a larger one overflows the offset. */
    private const int PAGE_MAX = 100_000;

    public function __construct(
        private StoreListingChange $change,
        private Imports $imports,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function handle(ListStoreFills $query): ImportList
    {
        $store = $this->change->authorize(self::PERMISSION, $query->storeId)->value;
        $page = min(max($query->page, 1), self::PAGE_MAX);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);
        [$imports, $total] = $this->imports->summaries(ImportHeader::STORE_FILL, $store, $page, $perPage);

        return new ImportList($imports, $total, $page, $perPage);
    }
}
