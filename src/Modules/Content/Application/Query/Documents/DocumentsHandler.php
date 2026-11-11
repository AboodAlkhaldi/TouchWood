<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Documents;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Enums\ProductStage;
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
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\StaffNames;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

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
    /** Add Product's page (§9.3c S4). */
    public const int PRODUCTS = 20;

    public function __construct(
        private ContentAccess $access,
        private Documents $documents,
        private SlugHistory $history,
        private ScreenReads $reads,
        private UnreadTranslations $marks,
        private StaffNames $names,
        private CatalogApi $catalog,
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
    public function list(DocumentKind $kind, ?string $storeId, ?Publication $tab, ?string $after = null, ?string $categoryId = null): array
    {
        $store = $this->access->authorize(self::permission($kind), $storeId);
        $read = $this->reads->documents($kind, $store->id, $tab, $after, $categoryId);
        // "By" is a name (W7): one read for the page's rows; someone no longer staff reads as nobody.
        $names = $this->names->forReader(array_values(array_filter(array_map(static fn (array $row): ?string => is_string($row['changedBy'] ?? null) ? $row['changedBy'] : null, $read['rows']))));
        $read['rows'] = array_map(static fn (array $row): array => [...$row, 'changedBy' => is_string($row['changedBy'] ?? null) ? ($names[strtolower($row['changedBy'])] ?? null)?->name : null], $read['rows']);

        return $read;
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
            'storeId' => $document->storeId,
            'status' => $document->status->value,
            'publishedAt' => $document->publishedAt?->format(DATE_ATOM),
            'version' => $document->version,
            'translationUnread' => $this->marks->has($kind->value, $document->id),
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

    /**
     * The products a post or a project may link (§1.12, §1.13: "ready products"): the store's ready products with a size
     * switched on there (Catalog's `readySwitchedOn`, filtered in its query, so a page is never short), found by a name in
     * either language or a code, 20 at a time after `$after` (the `next` of the page before; §9.3c S4). Two queries.
     *
     * @return array{products: list<array{id: string, nameAr: string, nameEn: string|null, archived: bool}>, next: string|null}
     *
     * @throws ContentError|Unauthorized
     */
    public function findProducts(DocumentKind $kind, ?string $storeId, ?string $text, ?string $after = null): array
    {
        $store = $this->access->authorize(self::permission($kind), $storeId);
        $found = $this->catalog->searchProducts(StoreId::fromString($store->id), $text, true, null, $after, self::PRODUCTS);

        return [
            'products' => array_values(array_map(static fn (FoundProductDto $product): array => ['id' => strtolower($product->productId), 'nameAr' => $product->nameAr, 'nameEn' => $product->nameEn, 'archived' => false], $found->rows)),
            'next' => $found->next,
        ];
    }

    /**
     * The products an item links, by name, in its order: one archived since it was linked is marked so (§1.1 rule 17).
     * One query; called once the item's form has been read under its permission.
     *
     * @param  list<string>  $productIds
     * @return list<array{id: string, nameAr: string, nameEn: string|null, archived: bool}>
     */
    public function linkedProducts(array $productIds): array
    {
        $found = $productIds === [] ? [] : $this->catalog->products($productIds);
        $linked = [];

        foreach ($productIds as $id) {
            $product = $found[strtolower($id)] ?? null;

            if ($product instanceof ProductDto) {
                $linked[] = ['id' => strtolower($id), 'nameAr' => $product->nameAr, 'nameEn' => $product->nameEn, 'archived' => $product->stage === ProductStage::Archived];
            }
        }

        return $linked;
    }
}
