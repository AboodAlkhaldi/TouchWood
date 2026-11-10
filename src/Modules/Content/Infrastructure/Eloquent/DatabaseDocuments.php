<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\Document;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\DocumentText;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Documents;
use Modules\Content\Domain\ValueObject\RichText;
use stdClass;

/**
 * `Documents` in SQL (content.md §5): a page, post or project with its translations, body images, products and
 * photos, read and written whole. A row no document names is removed on save (§5), since every save rewrites them.
 */
final readonly class DatabaseDocuments implements Documents
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(DocumentKind $kind, string $id): ?Document
    {
        return $this->read($kind, $id, false);
    }

    public function lock(DocumentKind $kind, string $id): ?Document
    {
        return $this->read($kind, $id, true);
    }

    public function add(Document $document, ?string $by): void
    {
        ForeignKeyRefusals::guard(function () use ($document, $by): void {
            $tables = DocumentTables::of($document->kind);
            $this->db->table($tables->items)->insert([
                'id' => $document->id,
                'store_id' => $document->storeId,
                'created_by' => $by,
                'created_at' => Rows::at($document->updatedAt),
                ...self::written($document),
            ]);
            $this->writeParts($document);
        });
    }

    public function update(Document $document): void
    {
        ForeignKeyRefusals::guard(function () use ($document): void {
            $tables = DocumentTables::of($document->kind);
            $this->db->table($tables->items)->where('id', $document->id)->update(self::written($document));
            $this->writeParts($document);
        });
    }

    public function updateRow(Document $document): void
    {
        $this->db->table(DocumentTables::of($document->kind)->items)->where('id', $document->id)->update(self::written($document));
    }

    public function delete(DocumentKind $kind, string $id): void
    {
        $this->db->table(DocumentTables::of($kind)->items)->where('id', strtolower($id))->delete();
    }

    public function postsInCategory(string $categoryId): int
    {
        return $this->db->table('content.blog_posts')->where('category_id', strtolower($categoryId))->count();
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(Document $document): array
    {
        $written = [
            'status' => $document->status->value,
            'published_at' => Rows::at($document->publishedAt),
            'share_media_ar' => $document->shareAr,
            'share_media_en' => $document->shareEn,
            'version' => $document->version,
            'updated_by' => $document->updatedBy,
            'updated_at' => Rows::at($document->updatedAt),
        ];

        if ($document->kind === DocumentKind::Post) {
            $written['category_id'] = $document->categoryId;
            $written['cover_media_id'] = $document->coverMediaId;
        }

        return $written;
    }

    private function writeParts(Document $document): void
    {
        $tables = DocumentTables::of($document->kind);
        $owner = [$tables->owner => $document->id];

        // Images and translations first out, so an address another language held a moment ago is free.
        $this->db->table($tables->images)->where($owner)->delete();
        $this->db->table($tables->texts)->where($owner)->delete();

        foreach ($document->texts as $locale => $text) {
            $row = [
                ...$owner,
                'locale' => $locale,
                'store_id' => $document->storeId,
                'title' => $text->title,
                'slug' => $text->slug,
                'body' => $text->body->toJson(),
                'seo_title' => $text->seoTitle,
                'seo_description' => $text->seoDescription,
            ];

            if ($document->kind === DocumentKind::Post) {
                $row['summary'] = $text->summary;
                $row['cover_alt'] = $text->coverAlt;
            }

            if ($document->kind === DocumentKind::Project) {
                $row['city'] = $text->city;
            }

            $this->db->table($tables->texts)->insert($row);

            foreach ($text->images as $ref => $image) {
                $this->db->table($tables->images)->insert([...$owner, 'locale' => $locale, 'ref' => $ref, 'media_id' => $image['media'], 'alt' => $image['alt']]);
            }
        }

        if ($tables->products !== null) {
            $this->db->table($tables->products)->where($owner)->delete();

            foreach ($document->products as $position => $product) {
                $this->db->table($tables->products)->insert([...$owner, 'product_id' => $product, 'position' => $position]);
            }
        }

        if ($tables->photos !== null) {
            $this->db->table($tables->photos)->where($owner)->delete();

            foreach ($document->photos as $position => $photo) {
                $this->db->table($tables->photos)->insert([...$owner, 'position' => $position, 'media_id' => $photo]);
            }
        }
    }

    private function read(DocumentKind $kind, string $id, bool $lock): ?Document
    {
        $tables = DocumentTables::of($kind);
        $id = strtolower($id);
        $row = $this->db->selectOne("SELECT * FROM {$tables->items} WHERE id = ?".($lock ? ' FOR UPDATE' : ''), [$id]);

        if (! $row instanceof stdClass) {
            return null;
        }

        $images = [];

        foreach ($this->db->select("SELECT locale, ref, media_id, alt FROM {$tables->images} WHERE {$tables->owner} = ? ORDER BY locale, ref", [$id]) as $image) {
            /** @var stdClass $image */
            $images[(string) $image->locale][(int) $image->ref] = ['media' => (string) $image->media_id, 'alt' => (string) $image->alt];
        }

        $texts = [];

        foreach ($this->db->select("SELECT * FROM {$tables->texts} WHERE {$tables->owner} = ? ORDER BY locale", [$id]) as $text) {
            /** @var stdClass $text */
            $locale = (string) $text->locale;
            $texts[$locale] = new DocumentText(
                (string) $text->title,
                (string) $text->slug,
                RichText::fromJson("body_{$locale}", (string) $text->body),
                Rows::text($text->seo_title),
                Rows::text($text->seo_description),
                Rows::text($text->summary ?? null),
                Rows::text($text->cover_alt ?? null),
                Rows::text($text->city ?? null),
                $images[$locale] ?? [],
            );
        }

        $products = $tables->products === null ? [] : array_map(
            static fn (stdClass $product): string => (string) $product->product_id,
            $this->db->select("SELECT product_id FROM {$tables->products} WHERE {$tables->owner} = ? ORDER BY position", [$id]),
        );
        $photos = $tables->photos === null ? [] : array_map(
            static fn (stdClass $photo): string => (string) $photo->media_id,
            $this->db->select("SELECT media_id FROM {$tables->photos} WHERE {$tables->owner} = ? ORDER BY position", [$id]),
        );

        return new Document(
            $id,
            $kind,
            (string) $row->store_id,
            Publication::from((string) $row->status),
            Rows::moment($row->published_at),
            Rows::text($row->share_media_ar),
            Rows::text($row->share_media_en),
            Rows::text($row->category_id ?? null),
            Rows::text($row->cover_media_id ?? null),
            $texts,
            array_values($products),
            array_values($photos),
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at),
        );
    }
}
