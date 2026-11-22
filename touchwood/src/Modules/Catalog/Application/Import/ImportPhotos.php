<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use LogicException;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\PermissionScope;

/**
 * The photos of a zip being brought in (catalog.md §1.12, page part 3): each added to the media library
 * once, when a product first needs it, as public images under the import's own job — Platform checks
 * the type and size, keeps one file for the same image, and removes the file again if bringing in
 * rolls back.
 */
final class ImportPhotos
{
    /** @var array<string, string> path inside the zip => media id */
    private array $media = [];

    /**
     * @param  array<string, string>  $files  path inside the zip => its temporary file
     */
    public function __construct(
        private readonly PlatformApi $platform,
        private readonly array $files,
    ) {}

    /**
     * @param  list<string>  $paths  inside the zip
     * @return list<string> media ids, in the same order
     */
    public function ids(array $paths): array
    {
        return array_map(fn (string $path): string => $this->media[$path] ??= $this->platform->uploadMediaFor(new ModuleUploadDto(
            'catalog',
            CatalogPermissions::IMPORT_RUN,
            PermissionScope::global(),
            MediaVisibility::Public,
            $this->files[$path] ?? throw new LogicException("{$path} was not unpacked."),
            basename($path),
        )), $paths);
    }
}
