<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadProjectImage;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * Uploads a project's image (its photos, its story's, its share image) to Platform's library as a **public** file (content.md §1.1 rule 10), under the item's own
 * permission in its store (`uploadMediaFor`): one handler a permission (lesson 175). Platform keeps it to JPEG, PNG or
 * WebP and 10 MB, deduplicates it - the same image uploaded again answers the same file - and makes its sizes in the
 * background; until they are ready, nothing showing it can be published or scheduled.
 */
final readonly class UploadProjectImageHandler
{
    public const string PERMISSION = ContentPermissions::PROJECT_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the file's media id
     *
     * @throws DomainError|Unauthorized
     */
    public function handle(UploadProjectImage $command): string
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);

        return strtolower($this->platform->uploadMediaFor(new ModuleUploadDto(
            'content',
            self::PERMISSION,
            PermissionScope::store(StoreId::fromString($store->id)),
            MediaVisibility::Public,
            $command->path,
            $command->fileName,
        )));
    }
}
