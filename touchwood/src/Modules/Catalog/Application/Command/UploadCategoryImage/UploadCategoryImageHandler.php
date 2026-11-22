<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadCategoryImage;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/**
 * **A category's photo, uploaded from the categories screen** (catalog.md §4.4, P5) under the tree's
 * job with All stores, as every change to the shared list. Platform keeps the file — one copy of an
 * identical public image — checks its type and size, and audits the upload under Catalog's job
 * (`uploadMediaFor`). Nothing in the catalog changes until the category's form is saved, and its
 * handler checks the photo again (`CatalogImages`).
 */
final readonly class UploadCategoryImageHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the photo's media id
     *
     * @throws DomainError|Unauthorized
     */
    public function handle(UploadCategoryImage $command): string
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
