<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Files;

use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleDeleteDto;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\PermissionScope;

/**
 * A company's papers and file answers, as Platform media (b2b.md §1.4).
 *
 * **B2B uploads every file inside its own use cases** (amendment 5), as a private file, under the
 * company's own permission — a customer holds no media permission. So every file B2B later lets go
 * of is one it created, and Platform deletes it only when asked by B2B, only if it is private, and
 * never while any use of it remains.
 */
final readonly class ApplicationFiles
{
    public function __construct(
        private PlatformApi $platform,
        private ApplicationRepository $applications,
    ) {}

    /**
     * Stores one paper, private, for the signed-in company account.
     *
     * @return string the media id
     */
    public function upload(string $path, string $originalFilename): string
    {
        return $this->platform->uploadMediaFor(new ModuleUploadDto(
            'b2b',
            B2BPermissions::APPLY,
            PermissionScope::global(),
            MediaVisibility::Private,
            $path,
            $originalFilename,
        ));
    }

    /**
     * Lets go of files an application no longer holds (amendment 4): the one a new upload replaced,
     * one removed from a draft, those of a discarded draft. Each is deleted only if no application
     * still holds it — a file carried from the last application sent stays with that application.
     *
     * Call inside the use case's transaction, **after** the application's own rows are written:
     * Platform refuses to delete a file any application still holds (§1.4), this one included.
     *
     * @param  list<string|null>  $mediaIds  what the application let go of; a null is nothing
     */
    public function release(array $mediaIds): void
    {
        $ids = array_values(array_unique(array_filter($mediaIds, static fn (?string $id): bool => $id !== null)));

        if ($ids === []) {
            return;
        }

        $held = array_flip($this->applications->stillHeld($ids));

        foreach ($ids as $mediaId) {
            if (isset($held[$mediaId])) {
                continue;
            }

            $this->platform->deleteMediaFor(new ModuleDeleteDto('b2b', B2BPermissions::APPLY, PermissionScope::global(), $mediaId));
        }
    }
}
