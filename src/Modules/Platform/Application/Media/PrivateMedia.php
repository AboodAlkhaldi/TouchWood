<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;

/**
 * Who reaches a private file through the media library (B2B step 3a, b2b.md amendment 8(a)).
 *
 * A private file — a company's papers — exists there only for holders of the admin-only
 * platform.media.private.view. To anyone else, describing, retrying or deleting it answers exactly
 * as for an id that never existed, so the panel never confirms that it is there; the same rule
 * Access follows for a customer outside a staff member's stores. A holder still needs the usual
 * permission for what they do, which each handler checks itself.
 *
 * A module acting on a file it holds for its own use (deleteMediaFor) is not asked this: it is
 * checked against its own permission instead.
 */
final readonly class PrivateMedia
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * Whether the current actor may see private files at all. The permission is store-free, so
     * holding it anywhere means holding it.
     */
    public function seen(): bool
    {
        return $this->authorizer->storesWith(PlatformPermissions::MEDIA_PRIVATE_VIEW) !== [];
    }

    /**
     * @throws MediaNotFound for a private file the current actor may not see
     */
    public function reach(Media $media): void
    {
        if ($media->visibility() === MediaVisibility::Private && ! $this->seen()) {
            throw new MediaNotFound($media->id());
        }
    }
}
