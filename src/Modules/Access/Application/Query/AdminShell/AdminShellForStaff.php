<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\AdminShell;

use Modules\Access\Application\Query\StaffReader;
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
 *
 * Read once per request and without a row lock (amendment 65): the shell asks twice - for the
 * panel's language and for the person block - and this used to load the staff member, their
 * assignment and their role through the write side's repositories, `for update`, both times. Bound
 * **scoped**; what it remembers is kept per person, so a request that signs someone in never shows
 * them the guest's answer.
 */
final class AdminShellForStaff
{
    /** @var array<string, AdminShellDto|null> staff id => what the panel shows of them */
    private array $shown = [];

    public function __construct(
        private readonly ActorContext $actors,
        private readonly StaffReader $staff,
        private readonly PlatformApi $platform,
    ) {}

    public function forCurrentStaff(): ?AdminShellDto
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            return null;
        }

        $id = strtolower($actor->id);

        if (! array_key_exists($id, $this->shown)) {
            $this->shown[$id] = $this->read($id);
        }

        return $this->shown[$id];
    }

    private function read(string $staffId): ?AdminShellDto
    {
        $row = $this->staff->shell($staffId);

        if ($row === null) {
            return null;
        }

        return new AdminShellDto(
            id: $staffId,
            // Both parts are required and trimmed when saved (StaffProfile).
            name: $row['first_name'].' '.$row['last_name'],
            roleNameAr: $row['role_name_ar'],
            roleNameEn: $row['role_name_en'],
            avatarUrl: $this->avatarUrl($row['avatar_media_id']),
            isSuperAdmin: $row['is_super_admin'],
            locale: $row['locale'],
        );
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
