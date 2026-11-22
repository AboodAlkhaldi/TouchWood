<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVisibility;

/**
 * A photo a list points at — a brand's logo, a category's photo — is a **public image** of Platform's
 * media library (handoff §5.5): shown through its CDN sizes, never a private file. Kept in its own
 * column with a RESTRICT key to `platform.media` (catalog.md §5), and detached when the library
 * deletes it (`CatalogImagesUsage`).
 */
final readonly class CatalogImages
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    /**
     * @return string|null the media id, lower-case; null for none
     *
     * @throws InvalidCatalogAttribute
     */
    public function check(string $attribute, ?string $mediaId): ?string
    {
        if ($mediaId === null || trim($mediaId) === '') {
            return null;
        }

        $media = $this->platform->media(strtolower(trim($mediaId)));

        if ($media === null || $media->visibility !== MediaVisibility::Public || ! str_starts_with($media->mime, 'image/')) {
            throw new InvalidCatalogAttribute($attribute, 'a public image of the media library');
        }

        return $media->id;
    }
}
