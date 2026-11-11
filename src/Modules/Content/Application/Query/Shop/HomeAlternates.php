<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

use Modules\Platform\Public\Dto\StoreDto;

/**
 * **The home pages name each other across the stores that are on** (content.md §1.14, Q21 (a)): each by its language
 * and its store's country (`ar-SA`, `en-AE`...), the sitemap's and the home page's head alike, so the two never
 * disagree. A store's country is not unique (platform.stores holds no such rule), and one name may name one page: of
 * two on stores in one country, the first in the stores' order is named for it, and a later one's home names only its
 * own two languages, plainly (the build's pick, PR 4 review, §9.3a B4).
 */
final class HomeAlternates
{
    /**
     * @param  list<StoreDto>  $onStores  every store that is on, in their order
     * @param  string  $root  the site's root, `https://host`, with no trailing slash
     * @return array<string, string> hreflang => the home's address
     */
    public static function of(StoreDto $store, array $onStores, string $root): array
    {
        $homes = [];

        foreach ($onStores as $other) {
            foreach (['ar', 'en'] as $locale) {
                $homes[$locale.'-'.strtoupper($other->countryCode)] ??= "{$root}/{$other->code}/{$locale}";
            }
        }

        if (($homes['ar-'.strtoupper($store->countryCode)] ?? null) !== "{$root}/{$store->code}/ar") {
            return ['ar' => "{$root}/{$store->code}/ar", 'en' => "{$root}/{$store->code}/en"];
        }

        return $homes;
    }
}
