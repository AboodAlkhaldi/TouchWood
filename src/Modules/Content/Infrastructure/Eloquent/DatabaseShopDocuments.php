<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Query\Shop\ShopDocuments;
use Modules\Content\Domain\Model\DocumentKind;
use stdClass;

/**
 * `ShopDocuments` in SQL (content.md §4.5): an item's page is **two reads** - the item with its words in both languages,
 * then its body's files, products and photos together - and a list one. Only `PUBLISHED` rows answer. A language is
 * only ever `ar` or `en` when it names a column.
 */
final readonly class DatabaseShopDocuments implements ShopDocuments
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function published(DocumentKind $kind, string $storeId, string $locale, string $slug): ?array
    {
        $tables = DocumentTables::of($kind);
        $locale = self::locale($locale);
        $other = $locale === 'ar' ? 'en' : 'ar';
        $extra = match ($kind) {
            DocumentKind::Page => '',
            DocumentKind::Post => ", t.summary, t.cover_alt, i.cover_media_id, c.name_{$locale} AS category_name, c.slug_{$locale} AS category_slug",
            DocumentKind::Project => ', t.city',
        };
        $join = $kind === DocumentKind::Post ? ' LEFT JOIN content.blog_categories c ON c.id = i.category_id' : '';

        $row = $this->db->selectOne(
            "SELECT i.id, i.published_at, i.share_media_{$locale} AS share, t.title, t.slug, t.body, t.seo_title, t.seo_description, o.slug AS other_slug{$extra}
             FROM {$tables->items} i JOIN {$tables->texts} t ON t.{$tables->owner} = i.id AND t.locale = ?
             LEFT JOIN {$tables->texts} o ON o.{$tables->owner} = i.id AND o.locale = ?{$join}
             WHERE i.store_id = ? AND t.slug = ? AND i.status = 'PUBLISHED'",
            [$locale, $other, strtolower($storeId), $slug],
        );

        if (! $row instanceof stdClass) {
            return null;
        }

        $id = (string) $row->id;
        // The body's files in this language, the products and the photos, in one read.
        $parts = ["SELECT 'image' AS what, ref, media_id::text AS id, alt, 0 AS position FROM {$tables->images} WHERE {$tables->owner} = ? AND locale = ?"];
        $bindings = [$id, $locale];

        if ($tables->products !== null) {
            $parts[] = "SELECT 'product', NULL, product_id::text, NULL, position FROM {$tables->products} WHERE {$tables->owner} = ?";
            $bindings[] = $id;
        }

        if ($tables->photos !== null) {
            $parts[] = "SELECT 'photo', NULL, media_id::text, NULL, position FROM {$tables->photos} WHERE {$tables->owner} = ?";
            $bindings[] = $id;
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT * FROM ('.implode(' UNION ALL ', $parts).') parts ORDER BY what, position, ref', $bindings);
        $of = static fn (string $what): array => array_values(array_filter($rows, static fn (stdClass $part): bool => $part->what === $what));

        return [
            'id' => $id,
            'title' => (string) $row->title,
            'slug' => (string) $row->slug,
            'otherSlug' => Rows::text($row->other_slug),
            'body' => Rows::decode($row->body),
            'seoTitle' => Rows::text($row->seo_title),
            'seoDescription' => Rows::text($row->seo_description),
            'shareMediaId' => Rows::text($row->share),
            'publishedAt' => Rows::moment($row->published_at),
            'summary' => Rows::text($row->summary ?? null),
            'coverMediaId' => Rows::text($row->cover_media_id ?? null),
            'coverAlt' => Rows::text($row->cover_alt ?? null),
            'city' => Rows::text($row->city ?? null),
            'category' => Rows::text($row->category_slug ?? null) === null ? null : ['name' => (string) $row->category_name, 'slug' => (string) $row->category_slug],
            'images' => array_map(static fn (stdClass $image): array => ['ref' => (int) $image->ref, 'mediaId' => strtolower((string) $image->id), 'alt' => Rows::text($image->alt)], $of('image')),
            'products' => array_map(static fn (stdClass $product): string => strtolower((string) $product->id), $of('product')),
            'photos' => array_map(static fn (stdClass $photo): string => strtolower((string) $photo->id), $of('photo')),
        ];
    }

    public function currentSlug(DocumentKind $kind, string $itemId, string $locale): ?string
    {
        $tables = DocumentTables::of($kind);

        $slug = $this->db->selectOne(
            "SELECT t.slug FROM {$tables->items} i JOIN {$tables->texts} t ON t.{$tables->owner} = i.id AND t.locale = ? WHERE i.id = ? AND i.status = 'PUBLISHED'",
            [self::locale($locale), strtolower($itemId)],
        );

        return $slug instanceof stdClass ? (string) $slug->slug : null;
    }

    public function posts(string $storeId, string $locale, ?string $categoryId, ?array $after, int $limit): array
    {
        $locale = self::locale($locale);
        $bindings = [$locale, strtolower($storeId)];
        $where = '';

        if ($categoryId !== null) {
            $where .= ' AND p.category_id = ?';
            $bindings[] = strtolower($categoryId);
        }

        if ($after !== null) {
            $where .= ' AND (p.published_at, p.id) < (?::timestamptz, ?)';
            array_push($bindings, $after[0], strtolower($after[1]));
        }

        $bindings[] = $limit;

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT p.id, p.published_at, p.cover_media_id, t.title, t.slug, t.summary, t.cover_alt, c.name_{$locale} AS category
             FROM content.blog_posts p JOIN content.blog_post_translations t ON t.post_id = p.id AND t.locale = ?
             LEFT JOIN content.blog_categories c ON c.id = p.category_id
             WHERE p.store_id = ? AND p.status = 'PUBLISHED'{$where} ORDER BY p.published_at DESC, p.id DESC LIMIT ?",
            $bindings,
        );

        return array_map(static fn (stdClass $row): array => [
            'id' => strtolower((string) $row->id),
            'title' => (string) $row->title,
            'slug' => (string) $row->slug,
            'summary' => Rows::text($row->summary),
            'coverMediaId' => Rows::text($row->cover_media_id),
            'coverAlt' => Rows::text($row->cover_alt),
            'publishedAt' => Rows::moment($row->published_at) ?? throw new \LogicException('A published post has its moment.'),
            'category' => Rows::text($row->category),
        ], $rows);
    }

    public function projects(string $storeId, string $locale, ?array $after, int $limit): array
    {
        $bindings = [self::locale($locale), strtolower($storeId)];
        $where = '';

        if ($after !== null) {
            $where = ' AND (p.published_at, p.id) < (?::timestamptz, ?)';
            array_push($bindings, $after[0], strtolower($after[1]));
        }

        $bindings[] = $limit;

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT p.id, p.published_at, t.title, t.slug, t.city,
                    (SELECT ph.media_id FROM content.project_photos ph WHERE ph.project_id = p.id ORDER BY ph.position LIMIT 1) AS photo
             FROM content.projects p JOIN content.project_translations t ON t.project_id = p.id AND t.locale = ?
             WHERE p.store_id = ? AND p.status = 'PUBLISHED'{$where} ORDER BY p.published_at DESC, p.id DESC LIMIT ?",
            $bindings,
        );

        return array_map(static fn (stdClass $row): array => [
            'id' => strtolower((string) $row->id),
            'title' => (string) $row->title,
            'slug' => (string) $row->slug,
            'city' => Rows::text($row->city),
            'photoMediaId' => Rows::text($row->photo),
            'publishedAt' => Rows::moment($row->published_at) ?? throw new \LogicException('A published project has its moment.'),
        ], $rows);
    }

    public function blogCategories(string $storeId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT c.id, c.name_ar, c.name_en, c.slug_ar, c.slug_en FROM content.blog_categories c
             WHERE c.store_id = ? AND EXISTS (SELECT 1 FROM content.blog_posts p WHERE p.category_id = c.id AND p.status = 'PUBLISHED')
             ORDER BY c.position, c.id",
            [strtolower($storeId)],
        );

        return array_map(static fn (stdClass $row): array => [
            'id' => strtolower((string) $row->id),
            'name_ar' => (string) $row->name_ar,
            'name_en' => (string) $row->name_en,
            'slug_ar' => (string) $row->slug_ar,
            'slug_en' => (string) $row->slug_en,
        ], $rows);
    }

    public function anyPublished(DocumentKind $kind, string $storeId): bool
    {
        return $this->db->table(DocumentTables::of($kind)->items)->where('store_id', strtolower($storeId))->where('status', 'PUBLISHED')->exists();
    }

    public function run(string $storeId, string $locale, string $slug): ?array
    {
        $locale = self::locale($locale);
        $other = $locale === 'ar' ? 'en' : 'ar';

        $row = $this->db->selectOne(
            "SELECT r.id, r.state, r.ends_at, r.slug_{$locale} AS slug, r.slug_{$other} AS other_slug, c.name_{$locale} AS name, c.name_ar,
                    r.slug_{$locale} IS DISTINCT FROM ? AS in_other
             FROM content.campaign_runs r JOIN content.campaigns c ON c.id = r.campaign_id
             WHERE r.store_id = ? AND (r.slug_{$locale} = ? OR r.slug_{$other} = ?)
             ORDER BY (r.slug_{$locale} = ?) DESC LIMIT 1",
            [$slug, strtolower($storeId), $slug, $slug, $slug],
        );

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'id' => strtolower((string) $row->id),
            'state' => (string) $row->state,
            'endsAt' => Rows::moment($row->ends_at) ?? throw new \LogicException('A run has its end.'),
            'name' => Rows::text($row->name) ?? (string) $row->name_ar,
            'slug' => Rows::text($row->slug),
            'otherSlug' => Rows::text($row->other_slug),
            'inOtherLanguage' => (bool) $row->in_other,
        ];
    }

    /** @return 'ar'|'en' */
    private static function locale(string $locale): string
    {
        return $locale === 'en' ? 'en' : 'ar';
    }
}
