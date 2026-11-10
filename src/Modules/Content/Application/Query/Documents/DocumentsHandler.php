<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Documents;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Application\Text\MarkText;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\DocumentText;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Documents;
use Modules\Content\Domain\Repository\SlugHistory;
use Shared\Application\Unauthorized;

/**
 * What W7, W8 and W9 show (content.md §4.4), each under its kind's permission in the store: a tab of the list (title,
 * address, state, changed, by; a post's category; the tabs' counts), 50 at a time, last changed first; one item's
 * form, its bodies **written back as the editor's marks** (§1.2: a text saved unchanged stays as it was); the blog's
 * categories with their posts' counts; and **where Content's own links name an item** - the footer, the home page,
 * banners, the bar, smart bars, pop-ups - which Unpublish and Delete list first (§1.1 rule 16), by every address it
 * ever had.
 */
final readonly class DocumentsHandler
{
    public function __construct(
        private ContentAccess $access,
        private Documents $documents,
        private SlugHistory $history,
        private ScreenReads $reads,
    ) {}

    public static function permission(DocumentKind $kind): string
    {
        return match ($kind) {
            DocumentKind::Page => ContentPermissions::PAGE_MANAGE,
            DocumentKind::Post => ContentPermissions::BLOG_MANAGE,
            DocumentKind::Project => ContentPermissions::PROJECT_MANAGE,
        };
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws ContentError|Unauthorized
     */
    public function list(DocumentKind $kind, ?string $storeId, Publication $tab, ?string $after = null, ?string $categoryId = null): array
    {
        $store = $this->access->authorize(self::permission($kind), $storeId);

        return $this->reads->documents($kind, $store->id, $tab, $after, $categoryId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function document(DocumentKind $kind, string $id): array
    {
        $this->access->holds(self::permission($kind));
        $document = $this->documents->find($kind, $id);
        $this->access->authorize(self::permission($kind), $document?->storeId, item: true);

        if ($document === null) {
            return [];
        }

        return [
            'id' => $document->id,
            'status' => $document->status->value,
            'publishedAt' => $document->publishedAt?->format(DATE_ATOM),
            'version' => $document->version,
            ...$document->fields(),
            'texts' => array_map(static fn (DocumentText $text): array => [
                'title' => $text->title,
                'slug' => $text->slug,
                'body' => MarkText::marks($text->body),
                'images' => array_map(static fn (array $image): string => $image['media'], $text->images),
                'seoTitle' => $text->seoTitle,
                'seoDescription' => $text->seoDescription,
                'summary' => $text->summary,
                'coverAlt' => $text->coverAlt,
                'city' => $text->city,
            ], $document->texts),
            'products' => $document->products,
            'photos' => $document->photos,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ContentError|Unauthorized
     */
    public function blogCategories(?string $storeId): array
    {
        $store = $this->access->authorize(ContentPermissions::BLOG_MANAGE, $storeId);

        return $this->reads->blogCategories($store->id);
    }

    /**
     * @return list<array{place: string, id: string}>
     *
     * @throws ContentError|Unauthorized
     */
    public function linksTo(DocumentKind $kind, string $id): array
    {
        $this->access->holds(self::permission($kind));
        $document = $this->documents->find($kind, $id);
        $store = $this->access->authorize(self::permission($kind), $document?->storeId, item: true);
        $paths = array_map(static fn (string $slug): string => $kind->pathPrefix().$slug, $this->history->slugsOf($kind->value, $id));
        $match = new LinkMatch($paths, $store->code, (string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $found = [];

        foreach ($this->reads->links($store->id) as $link) {
            if ($match->names($link['link'])) {
                $found[$link['place'].':'.$link['id']] = ['place' => $link['place'], 'id' => $link['id']];
            }
        }

        ksort($found, SORT_STRING);

        return array_values($found);
    }
}
