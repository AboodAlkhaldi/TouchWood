<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;

/**
 * The thumbnails of a page's photos, read together in one Platform read (`mediaUrlsOf`, catalog.md
 * §2.4): a photo whose sizes are not ready has none yet.
 */
final readonly class Thumbnails
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    /**
     * @param  list<string|null>  $mediaIds
     * @return array<string, string> media id => its thumbnail's address
     */
    public function of(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter($mediaIds, static fn (?string $id): bool => $id !== null && $id !== '')));
        $thumbs = [];

        foreach ($ids === [] ? [] : $this->platform->mediaUrlsOf($ids) as $id => $urls) {
            $thumb = self::thumb($urls);

            if ($thumb !== null) {
                $thumbs[$id] = $thumb;
            }
        }

        return $thumbs;
    }

    private static function thumb(MediaUrlsDto $urls): ?string
    {
        $sizes = $urls->variants[MediaSize::Thumb->slug()] ?? [];

        // Best first: a browser that cannot draw one of these is a browser we do not have.
        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $sizes[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }
}
