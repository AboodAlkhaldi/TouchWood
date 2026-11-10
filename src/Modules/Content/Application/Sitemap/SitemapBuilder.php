<?php

declare(strict_types=1);

namespace Modules\Content\Application\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use LogicException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\SitemapEntryDto;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * A store's sitemap (content.md §1.14): **its home, categories, brands, listed products, pages, posts and projects,
 * in both languages with alternates and the last-changed date, split at 50,000 addresses**. Not-available and
 * noindex pages are never in it: Catalog's entries are what a guest finds listed (`CatalogApi::sitemapEntries`),
 * Content's what is published. The blog's and the projects' lists are in it once they have something to list
 * (H3, H5).
 *
 * Each address names its other language as its alternate, and itself; **the home pages name each other across the
 * stores that are on** (Q21 (a)), each by its language and its store's country. Addresses are written on the site's
 * canonical root, each word percent-encoded.
 */
final readonly class SitemapBuilder
{
    /** Addresses a file holds at most (the sitemap protocol's limit). */
    public const int PER_FILE = 50000;

    /** Catalog's entries asked a page at a time. */
    private const int CATALOG_PAGE = 1000;

    /** Pages of Catalog's entries asked at most: ten million entries, so the loop has its end (lesson 194). */
    private const int CATALOG_PAGES = 10000;

    /** @var array<string, string> Catalog's kinds => their shop path */
    private const array CATALOG_PATHS = [
        SitemapEntryDto::CATEGORY => 'categories',
        SitemapEntryDto::BRAND => 'brands',
        SitemapEntryDto::PRODUCT => 'products',
    ];

    /** @var array<string, string> Content's kinds => their shop path */
    private const array CONTENT_PATHS = [
        SitemapSources::PAGE => 'pages',
        SitemapSources::POST => 'blog',
        SitemapSources::PROJECT => 'projects',
        SitemapSources::BLOG_CATEGORY => 'blog/categories',
    ];

    public function __construct(
        private CatalogApi $catalog,
        private SitemapSources $sources,
    ) {}

    /**
     * @param  list<StoreDto>  $onStores  every store that is on, the home pages' alternates
     * @param  string  $root  the site's root, `https://host`, with no trailing slash
     * @param  int<1, 50000>  $perFile  addresses a file holds at most (smaller only in tests)
     * @return list<array{xml: string, urls: int}> its files in order, at least one
     */
    public function build(StoreDto $store, array $onStores, string $root, int $perFile = self::PER_FILE): array
    {
        $parts = [];
        $urls = [];

        foreach ($this->urls($store, $onStores, $root) as $url) {
            $urls[] = $url;

            if (count($urls) === $perFile) {
                $parts[] = ['xml' => self::file($urls), 'urls' => count($urls)];
                $urls = [];
            }
        }

        if ($urls !== [] || $parts === []) {
            $parts[] = ['xml' => self::file($urls), 'urls' => count($urls)];
        }

        return $parts;
    }

    /**
     * The index of every on store's files (`/sitemap.xml`).
     *
     * @param  list<array{loc: string, built_at: DateTimeImmutable}>  $files
     */
    public static function index(array $files): string
    {
        $entries = array_map(static fn (array $file): string => '<sitemap><loc>'.self::xml($file['loc']).'</loc><lastmod>'.$file['built_at']->format(DateTimeInterface::ATOM).'</lastmod></sitemap>', $files);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode("\n", $entries).($entries === [] ? '' : "\n")
            .'</sitemapindex>'."\n";
    }

    /** A store's file's address: part 1 at `/{store}/sitemap.xml`, part n at `/{store}/sitemap-{n}.xml`. */
    public static function fileAddress(string $root, string $storeCode, int $part): string
    {
        return $root.'/'.$storeCode.($part === 1 ? '/sitemap.xml' : "/sitemap-{$part}.xml");
    }

    /**
     * @param  list<StoreDto>  $onStores
     * @return Generator<int, string>
     */
    private function urls(StoreDto $store, array $onStores, string $root): Generator
    {
        // The home: every on store's, in both languages, named by language and country. A store's country is not
        // unique (platform.stores holds no such rule), and one name may name one page: of two on stores in one
        // country, the first in the stores' order is named for it, and a later one's home names only its own two
        // languages, plainly (the build's pick, PR 4 review).
        $homes = [];

        foreach ($onStores as $other) {
            foreach (['ar', 'en'] as $locale) {
                $homes[$locale.'-'.strtoupper($other->countryCode)] ??= "{$root}/{$other->code}/{$locale}";
            }
        }

        if (($homes['ar-'.strtoupper($store->countryCode)] ?? null) !== "{$root}/{$store->code}/ar") {
            $homes = ['ar' => "{$root}/{$store->code}/ar", 'en' => "{$root}/{$store->code}/en"];
        }

        foreach (['ar', 'en'] as $locale) {
            yield self::url("{$root}/{$store->code}/{$locale}", null, $homes);
        }

        // Content's own pages, and the lists that have something to list.
        $content = $this->sources->entries($store->id);
        $lists = [];

        foreach ($content as $entry) {
            if ($entry['kind'] === SitemapSources::POST || $entry['kind'] === SitemapSources::PROJECT) {
                $lists[self::CONTENT_PATHS[$entry['kind']]] = true;
            }
        }

        foreach (array_keys($lists) as $list) {
            yield from self::pair($root, $store->code, $list, $list, $list, null);
        }

        foreach ($content as $entry) {
            $path = self::CONTENT_PATHS[$entry['kind']];

            yield from self::pair($root, $store->code, $path, $entry['slug_ar'], $entry['slug_en'], $entry['changed_at'], slugs: true);
        }

        // Catalog's, a page at a time.
        $after = null;

        for ($page = 0; $page < self::CATALOG_PAGES; $page++) {
            $found = $this->catalog->sitemapEntries($store->storeId(), $after, self::CATALOG_PAGE);

            foreach ($found->entries as $entry) {
                yield from self::pair($root, $store->code, self::CATALOG_PATHS[$entry->kind], $entry->slugAr, $entry->slugEn, $entry->changedAt, slugs: true);
            }

            if ($found->next === null) {
                return;
            }

            $after = $found->next;
        }

        throw new LogicException('The catalog\'s sitemap entries never ended.');
    }

    /**
     * One page's addresses in the languages it has, each naming both.
     *
     * @param  bool  $slugs  whether `ar` and `en` are slugs under `path` (a page) or the whole path (a list)
     * @return Generator<int, string>
     */
    private static function pair(string $root, string $storeCode, string $path, ?string $ar, ?string $en, ?DateTimeImmutable $changedAt, bool $slugs = false): Generator
    {
        $addresses = [];

        foreach (['ar' => $ar, 'en' => $en] as $locale => $slug) {
            if ($slug !== null && $slug !== '') {
                $addresses[$locale] = "{$root}/{$storeCode}/{$locale}/".RedirectPath::encoded($slugs ? "{$path}/{$slug}" : $path);
            }
        }

        foreach ($addresses as $address) {
            yield self::url($address, $changedAt, $addresses);
        }
    }

    /**
     * @param  array<string, string>  $alternates  hreflang => address
     */
    private static function url(string $loc, ?DateTimeImmutable $changedAt, array $alternates): string
    {
        $links = '';

        foreach ($alternates as $language => $href) {
            $links .= '<xhtml:link rel="alternate" hreflang="'.self::xml($language).'" href="'.self::xml($href).'"/>';
        }

        return '<url><loc>'.self::xml($loc).'</loc>'.($changedAt === null ? '' : '<lastmod>'.$changedAt->format(DateTimeInterface::ATOM).'</lastmod>').$links.'</url>';
    }

    /**
     * @param  list<string>  $urls
     */
    private static function file(array $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
            .implode("\n", $urls).($urls === [] ? '' : "\n")
            .'</urlset>'."\n";
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
