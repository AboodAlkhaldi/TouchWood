<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\ValueObject\ProductVideo;
use Modules\Platform\Public\YouTubeLink;

/**
 * A product's videos as the Photos tab sends them (flags-2026-11.md S4.4, catalog.md amendment 48): at most 10 (F21),
 * in order, each a YouTube link - only the video's id is kept - with an optional title per language, each video once.
 * Read whole before anything is locked: nothing here asks the database.
 */
final class ProductVideos
{
    public const int MAX = 10;

    /**
     * @param  array<array-key, mixed>  $sent  each `['link' => …, 'title_ar' => …, 'title_en' => …]`, in order
     * @return list<ProductVideo>
     *
     * @throws InvalidCatalogAttribute|TooMany
     */
    public static function read(array $sent): array
    {
        if (count($sent) > self::MAX) {
            throw new TooMany('videos', self::MAX);
        }

        $videos = [];
        $ids = [];

        foreach (array_values($sent) as $video) {
            $link = is_array($video) && is_string($video['link'] ?? null) ? $video['link'] : '';
            $id = YouTubeLink::id($link) ?? throw new InvalidCatalogAttribute('videos', 'a YouTube link', InvalidCatalogAttribute::YOUTUBE_LINK);

            if (in_array($id, $ids, true)) {
                throw new InvalidCatalogAttribute('videos', 'each video once', InvalidCatalogAttribute::VIDEO_ONCE);
            }

            $ids[] = $id;
            $videos[] = ProductVideo::of($id, self::title($video, 'title_ar'), self::title($video, 'title_en'));
        }

        return $videos;
    }

    private static function title(mixed $video, string $key): ?string
    {
        return is_array($video) && is_string($video[$key] ?? null) ? $video[$key] : null;
    }
}
