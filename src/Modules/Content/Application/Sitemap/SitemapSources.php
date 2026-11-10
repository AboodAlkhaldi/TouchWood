<?php

declare(strict_types=1);

namespace Modules\Content\Application\Sitemap;

use DateTimeImmutable;

/**
 * Content's own pages a store's sitemap lists (content.md §1.14): its published pages, posts and projects, the blog
 * categories holding a published post, each with its current address per language and its last change - and so
 * whether the blog's and the projects' lists have a page at all (H3, H5: hidden until something is published).
 */
interface SitemapSources
{
    public const string PAGE = 'page';

    public const string POST = 'post';

    public const string PROJECT = 'project';

    public const string BLOG_CATEGORY = 'blog_category';

    /**
     * @return list<array{kind: string, slug_ar: string|null, slug_en: string|null, changed_at: DateTimeImmutable}>
     */
    public function entries(string $storeId): array;
}
