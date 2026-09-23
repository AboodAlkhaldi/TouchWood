<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\AdminShell;

use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * The person the admin panel is being shown to (frontend.md §2.2, stage 2b step 1).
 *
 * It takes no permission of its own: whoever is signed in may see their own name. Nobody signed in
 * means null, and the shell shows the sign-in page instead.
 */
final readonly class AdminShellForStaff
{
    public function __construct(
        private ActorContext $actors,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private RoleRepository $roles,
        private PlatformApi $platform,
    ) {}

    public function forCurrentStaff(string $locale): ?AdminShellDto
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            return null;
        }

        $staff = $this->staff->byId($actor->id);

        if ($staff === null) {
            return null;
        }

        $profile = $staff->profile();

        return new AdminShellDto(
            id: $staff->id(),
            name: trim($profile->firstName.' '.$profile->lastName),
            roleLabel: $this->roleLabel($staff->id(), $locale),
            avatarUrl: $this->avatarUrl($staff->avatarMediaId()),
            isSuperAdmin: $staff->isSuperAdmin(),
            locale: $staff->language()->value,
        );
    }

    /**
     * The role's own name, in the language the panel is being read in. A Super Admin holds no role
     * at all, and a personal role - one made for this person alone - still has a name.
     */
    private function roleLabel(string $staffId, string $locale): ?string
    {
        $roleId = $this->assignments->byStaff($staffId)?->roleId();

        if ($roleId === null) {
            return null;
        }

        $name = $this->roles->byId($roleId)?->name();

        if ($name === null) {
            return null;
        }

        return $locale === 'en' ? $name->en : $name->ar;
    }

    /**
     * The picture, asked of Platform as any other module asks for media.
     *
     * An avatar is a public image, and a public image is shown only through its variants - never
     * its original. The thumb is the size a 32px sidebar picture wants; JPEG is taken last because
     * every browser can draw it, and the smaller formats first. Nothing at all until the variants
     * are generated, which is a moment after the upload, and nothing if the media row has gone:
     * both mean no picture, never a broken image.
     */
    private function avatarUrl(?string $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $thumb = $this->platform->mediaUrls($mediaId)?->variants[MediaSize::Thumb->slug()] ?? null;

        if (! is_array($thumb)) {
            return null;
        }

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $thumb[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }
}
