<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Storefront;

use Modules\Catalog\Application\Query\Shop\ShopLocale;
use Modules\Catalog\Public\Contracts\ShopCategoryTiles;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * `ShopCategoryTiles` from this viewer's cached menu (amendment 46(b)): its top categories in its order, and their
 * photos' sizes in one read of Platform's (`mediaUrlsOf`), none while a photo's sizes are not ready.
 */
final readonly class PageCategoryTiles implements ShopCategoryTiles
{
    public const int MAX = 12;

    public function __construct(
        private ShopVisit $visit,
        private PlatformApi $platform,
    ) {}

    public function read(StoreId $store, string $locale, int $limit): array
    {
        $tops = $this->visit->topCategories($store, ShopLocale::of($locale), min(max($limit, 1), self::MAX));
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $top): ?string => $top['image'], $tops))));
        $urls = $ids === [] ? [] : $this->platform->mediaUrlsOf($ids);

        return array_map(static function (array $top) use ($urls): array {
            $sizes = $top['image'] === null ? [] : ($urls[strtolower($top['image'])]->variants ?? []);

            return ['name' => $top['name'], 'slug' => $top['slug'], 'photo' => $sizes === [] ? null : $sizes];
        }, $tops);
    }
}
