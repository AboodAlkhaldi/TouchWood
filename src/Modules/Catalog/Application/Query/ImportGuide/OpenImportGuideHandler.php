<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ImportGuide;

use Modules\Catalog\Application\CatalogPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The files' guide** (docs/modules/catalog-import/): both formats, read by whoever fills a file -
 * a store's admins (`catalog.listing.fill` in some store, S10) or the Super Admin
 * (`catalog.import.run`, S11). Anyone else is refused, as each file's screen refuses them.
 */
final readonly class OpenImportGuideHandler
{
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(OpenImportGuide $query): void
    {
        if ($this->authorizer->storesWith(CatalogPermissions::LISTING_FILL) !== []) {
            return;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
    }
}
