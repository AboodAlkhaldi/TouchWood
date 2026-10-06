<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RebuildListing;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The repair** (catalog.md §3, §5.4): every listing row written again from Catalog's own tables,
 * the same rows each change writes — after a change of CDN address, or anything that left a row
 * behind. One transaction under the products' lock, so no change writes a row while it runs. It
 * changes nothing a person did, so it audits nothing.
 */
final readonly class RebuildListingHandler
{
    /** Reserved: the system's, or a Super Admin's. */
    public const string PERMISSION = CatalogPermissions::LISTING_REBUILD;

    public function __construct(
        private Authorizer $authorizer,
        private ListLocks $locks,
        private ListingRows $listingRows,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(RebuildListing $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function (): void {
            $this->locks->lock(ListLocks::PRODUCTS);
            $this->listingRows->rebuild();
        }, 3);
    }
}
