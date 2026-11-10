<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

/**
 * The rows the shop's cached Content is made from (content.md §1.4, §1.5), read only when the cache is cold. Moments
 * are ISO 8601 strings in UTC, so what is kept is plain data; every item scheduled and not ended is read, its start
 * and end with it, for the reader to filter by the clock.
 */
interface ShopReads
{
    /**
     * @return array{bar: array<string, mixed>|null, footer: array<string, mixed>|null, popups: list<array<string, mixed>>}
     */
    public function frame(string $storeId): array;

    /**
     * @return array{sections: list<array<string, mixed>>, banners: list<array<string, mixed>>, projects: list<array<string, mixed>>, posts: list<array<string, mixed>>}
     */
    public function home(string $storeId): array;
}
