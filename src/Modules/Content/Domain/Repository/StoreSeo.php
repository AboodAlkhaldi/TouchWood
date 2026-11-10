<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\SeoDefaults;

/**
 * Each store's SEO defaults and its sitemap's parts (content.md §1.14, §5: `content.seo_defaults`,
 * `content.sitemaps`).
 */
interface StoreSeo
{
    /** The store's defaults, locked when asked; null for a store never saved. */
    public function defaults(string $storeId, bool $lock = false): ?SeoDefaults;

    public function saveDefaults(SeoDefaults $defaults): void;

    /**
     * Every store's robots lines that has any, keyed by store id: one read for the robots file.
     *
     * @return array<string, list<string>>
     */
    public function robotsLines(): array;

    /**
     * The parts of every store's sitemap, keyed by store id, then part: one read for the index.
     *
     * @return array<string, array<int, array{file_key: string, urls: int, built_at: \DateTimeImmutable}>>
     */
    public function sitemapParts(): array;

    /** The file of one part of a store's sitemap, or null. */
    public function sitemapFile(string $storeId, int $part): ?string;

    /**
     * A store's parts replaced by these, all at once.
     *
     * @param  array<int, array{file_key: string, urls: int}>  $parts  part => its file and count
     * @return list<string> the files of the parts replaced, to delete once committed
     */
    public function replaceSitemap(string $storeId, array $parts, \DateTimeImmutable $at): array;

    /**
     * Every store's parts but these stores' removed.
     *
     * @param  list<string>  $keep
     * @return list<string> the files of the parts removed
     */
    public function removeSitemapsBut(array $keep): array;
}
