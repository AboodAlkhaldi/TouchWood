<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Sitemap\SitemapSources;
use stdClass;

/**
 * `SitemapSources` in SQL, one query: the store's published pages, posts and projects with their slug in each
 * language, the blog categories that hold a published post, and the policies published in the store (flags-2026-11.md
 * S3.1). A draft is never one.
 */
final readonly class DatabaseSitemapSources implements SitemapSources
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function entries(string $storeId): array
    {
        $documents = static fn (string $kind, string $items, string $translations, string $key): string => "SELECT '{$kind}' AS kind, i.id::text AS id, i.updated_at,"
            ." (SELECT t.slug FROM content.{$translations} t WHERE t.{$key} = i.id AND t.locale = 'ar') AS slug_ar,"
            ." (SELECT t.slug FROM content.{$translations} t WHERE t.{$key} = i.id AND t.locale = 'en') AS slug_en"
            ." FROM content.{$items} i WHERE i.store_id = ? AND i.status = 'PUBLISHED'";

        $rows = $this->db->select(
            $documents(self::PAGE, 'pages', 'page_translations', 'page_id')
            .' UNION ALL '.$documents(self::POST, 'blog_posts', 'blog_post_translations', 'post_id')
            .' UNION ALL '.$documents(self::PROJECT, 'projects', 'project_translations', 'project_id')
            ." UNION ALL SELECT '".self::BLOG_CATEGORY."', c.id::text, c.updated_at, c.slug_ar, c.slug_en FROM content.blog_categories c"
            ." WHERE c.store_id = ? AND EXISTS (SELECT 1 FROM content.blog_posts p WHERE p.category_id = c.id AND p.status = 'PUBLISHED')"
            // A policy serves a list of stores (flags-2026-11.md S3.1): listed in each store it is published in.
            ." UNION ALL SELECT '".self::POLICY."', i.id::text, i.updated_at,"
            ." (SELECT t.slug FROM content.policy_translations t WHERE t.policy_id = i.id AND t.locale = 'ar'),"
            ." (SELECT t.slug FROM content.policy_translations t WHERE t.policy_id = i.id AND t.locale = 'en')"
            ." FROM content.policies i JOIN content.policy_stores s ON s.policy_id = i.id AND s.store_id = ? WHERE i.status = 'PUBLISHED'"
            .' ORDER BY kind, id',
            array_fill(0, 5, strtolower($storeId)),
        );

        return array_values(array_map(static fn (stdClass $row): array => [
            'kind' => (string) $row->kind,
            'slug_ar' => Rows::text($row->slug_ar),
            'slug_en' => Rows::text($row->slug_en),
            'changed_at' => Rows::moment($row->updated_at) ?? throw new \LogicException('An item has its moment.'),
        ], $rows));
    }
}
