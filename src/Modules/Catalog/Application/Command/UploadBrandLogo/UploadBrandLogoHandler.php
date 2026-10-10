<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadBrandLogo;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/**
 * **A brand's logo, uploaded from the brands screen** (catalog.md §4.4, P5) under the brands' job with
 * All stores, as every change to the shared list. Platform keeps the file — one copy of an identical
 * public image — checks its type and size, and audits the upload under Catalog's job
 * (`uploadMediaFor`). Nothing in the catalog changes until the brand's form is saved, and its handler
 * checks the photo again (`CatalogImages`).
 */
final readonly class UploadBrandLogoHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the logo's media id
     *
     * @throws DomainError|Unauthorized
     */
    public function handle(UploadBrandLogo $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::allStores());

        return $this->platform->uploadMediaFor(new ModuleUploadDto(
            'catalog',
            self::PERMISSION,
            PermissionScope::allStores(),
            MediaVisibility::Public,
            $command->path,
            $command->fileName,
        ));
    }
}
