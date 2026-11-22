<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVisibility;

/**
 * A staff avatar is a public image already uploaded to Platform (spec §1.4).
 */
final readonly class Avatars
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function requireUsable(?string $mediaId): void
    {
        if ($mediaId === null) {
            return;
        }

        $media = $this->platform->media($mediaId);

        if ($media === null || $media->visibility !== MediaVisibility::Public || ! str_starts_with($media->mime, 'image/')) {
            throw new InvalidAccessAttribute('avatar', 'an uploaded public image');
        }
    }
}
