<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Response;
use Modules\Content\Application\Sitemap\SitemapBuilder;
use Modules\Content\Application\Sitemap\SitemapFiles;
use Modules\Content\Domain\Repository\StoreSeo;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The sitemaps (content.md §1.14): **`/sitemap.xml`, the index of each on store's files**, and each store's files
 * at `/{store}/sitemap.xml` (part 1) and `/{store}/sitemap-{n}.xml`. The files are the ones `BuildSitemapsJob` wrote,
 * read as they are; nothing is built in the request. **A store that is off answers not found and leaves the index
 * at once**, whatever files it still has.
 *
 * No session, no store context (routes: no middleware group): a crawler's read costs the store directory's cached
 * read and one query.
 */
final readonly class SitemapController
{
    public function index(PlatformApi $platform, StoreSeo $seo, Config $config): Response
    {
        $root = rtrim((string) $config->get('app.url'), '/');
        $parts = $seo->sitemapParts();
        $files = [];

        foreach ($platform->stores() as $store) {
            foreach ($parts[$store->id] ?? [] as $part => $file) {
                $files[] = ['loc' => SitemapBuilder::fileAddress($root, $store->code, $part), 'built_at' => $file['built_at']];
            }
        }

        return self::xml(SitemapBuilder::index($files));
    }

    public function store(string $store, PlatformApi $platform, StoreSeo $seo, SitemapFiles $files): Response
    {
        return self::file($store, 1, $platform, $seo, $files);
    }

    public function part(string $store, string $part, PlatformApi $platform, StoreSeo $seo, SitemapFiles $files): Response
    {
        // Part 1 is only ever /{store}/sitemap.xml: "sitemap-1.xml" names nothing.
        return (int) $part < 2 ? throw new NotFoundHttpException : self::file($store, (int) $part, $platform, $seo, $files);
    }

    private static function file(string $code, int $part, PlatformApi $platform, StoreSeo $seo, SitemapFiles $files): Response
    {
        $store = $platform->storeByCode($code);
        $key = $store === null ? null : $seo->sitemapFile($store->id, $part);
        $xml = $key === null ? null : $files->get($key);

        return $xml === null ? throw new NotFoundHttpException : self::xml($xml);
    }

    private static function xml(string $xml): Response
    {
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
