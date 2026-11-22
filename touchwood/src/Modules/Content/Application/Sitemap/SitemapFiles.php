<?php

declare(strict_types=1);

namespace Modules\Content\Application\Sitemap;

/**
 * Where the sitemaps' files are kept (content.md §5 `content.sitemaps`: "File in private storage, served by the
 * routes"): written by `BuildSitemapsJob`, read by the routes, never made in a shopper's request.
 */
interface SitemapFiles
{
    public function put(string $key, string $contents): void;

    /** The file's contents, or null when it is gone. */
    public function get(string $key): ?string;

    /**
     * @param  list<string>  $keys
     */
    public function delete(array $keys): void;
}
