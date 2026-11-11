<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadThemeImage;

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
 * Uploads a theme part's artwork to Platform's library as a **public** file (content.md §1.1 rule 10, §1.8), under the
 * themes' reserved permission - a Super Admin's - in the store the form names (`uploadMediaFor`): one handler a
 * permission (lesson 175). Platform keeps it to JPEG, PNG or WebP and 10 MB, and makes its sizes in the background;
 * until they are ready, the theme can't be scheduled. Whether a transparent part's file is a PNG or a WebP is the
 * theme's save's question (`ThemeRules`).
 */
final readonly class UploadThemeImageHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the file's media id
     *
     * @throws DomainError|Unauthorized
     */
    public function handle(UploadThemeImage $command): string
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
