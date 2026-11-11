<?php

declare(strict_types=1);

namespace Modules\Content\Application\Documents;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentImages;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Text\MarkText;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Model\Document;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\DocumentText;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\BlogCategories;
use Modules\Content\Domain\Repository\Documents;
use Modules\Content\Domain\Repository\SlugHistory;
use Modules\Content\Domain\ValueObject\RichText;
use Modules\Content\Domain\ValueObject\Slug;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * Pages, blog posts and projects, written (content.md §1.11-§1.13, §4.1) - one set of rules for the three kinds, each
 * called by its own handler under its own permission, once that handler has checked it:
 *
 * - **Saved with Arabic** (title; §1.1 rule 2); an English side only with its title. An address per language made
 *   from the title until typed (§1.1 rule 15), cut at 200 characters, refused when another item of the kind holds it
 *   now or held it before (`SlugTaken`); every address the item had is kept, so an old one forwards.
 * - **Bodies** are typed marks read into the structured text (§1.2); every image a body names must have its file
 *   ("a reference that resolves to nothing is refused"), a row no body names is not kept.
 * - **Published at once** (§1.1 rule 5), with **both languages** - title and body, a post's summary and cover
 *   description, every Arabic box's English - and **every image ready** (§1.1 rule 10). A published item stays held
 *   to those rules on every save: English blanked on it is refused.
 * - **Deleted only as a draft** (§1.1 rule 11), its addresses freed.
 * - **Two people**: a save over a newer version is refused with who and when (§1.1 rule 12, `StaleEdit`).
 * - Audited by value; a body as changed with its length (§1.1 rule 13).
 *
 * Lengths that are the build's picks: title 150, summary 200, cover description 200, city 100; SEO 70 and 160 (W7).
 */
final readonly class DocumentWriter
{
    public function __construct(
        private Documents $documents,
        private BlogCategories $categories,
        private CatalogApi $catalog,
        private ContentImages $images,
        private EditCheck $check,
        private SlugHistory $history,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private TranslationMarks $marks,
    ) {}

    /**
     * @return string the item's id
     *
     * @throws ContentError
     */
    public function save(DocumentKind $kind, DocumentInput $input, StoreDto $store, ?string $staffId): string
    {
        $was = $input->id === null ? null : $this->documents->find($kind, $input->id);
        $read = $this->read($kind, $input, $store, $was);

        return $this->db->transaction(function () use ($kind, $input, $store, $staffId, $read): string {
            $now = Moments::now();
            // The store's address lock before any row's (ContentLocks: store locks first, then rows).
            $this->check->lockAddresses($store->id);

            // The category held while the post is written, so a delete of it waits and then sees the post.
            if ($read['category'] !== null && $this->categories->lock($read['category']) === null) {
                throw new InvalidContentInput('category', InvalidContentInput::CATEGORY);
            }

            if ($input->id === null) {
                $document = $this->document(strtolower((string) Str::ulid()), $kind, $store, Publication::Draft, null, $read, 1, $staffId, $now);
                $this->check->addresses($store->id, $kind->value, $document->id, self::slugs($document));
                $this->documents->add($document, $staffId);
                $this->marks->saved($kind->value, $document->id, $store->id, $input->translated, $input->read, false);
                $this->platform->recordAudit(ContentAudit::entry("content.{$kind->key()}.created", "content.{$kind->key()}", $document->id, $store->id, self::changes(null, $document)));
                $this->cache->invalidate();

                return $document->id;
            }

            $was = $this->documents->lock($kind, $input->id) ?? throw new ContentNotFound;
            $this->check->version($input->version, $was->version, $was->updatedBy, $was->updatedAt, $store);
            $document = $this->document($was->id, $kind, $store, $was->status, $was->publishedAt, $read, $was->version + 1, $staffId, $now);

            if ($was->status === Publication::Published) {
                $this->requirePublishable($document);
            }

            $this->check->addresses($store->id, $kind->value, $document->id, self::slugs($document));
            $this->documents->update($document);
            // A published item shows what is saved at once: its unread English is refused, as its Publish would be.
            $this->marks->saved($kind->value, $document->id, $store->id, $input->translated, $input->read, $was->status === Publication::Published);
            $this->platform->recordAudit(ContentAudit::entry("content.{$kind->key()}.changed", "content.{$kind->key()}", $document->id, $store->id, self::changes($was, $document)));
            $this->cache->invalidate();

            return $document->id;
        }, 3);
    }

    /**
     * @throws ContentError
     */
    public function publish(DocumentKind $kind, string $id, int $version, StoreDto $store, ?string $staffId, bool $read = false): void
    {
        $this->db->transaction(function () use ($kind, $id, $version, $store, $staffId, $read): void {
            $was = $this->documents->lock($kind, $id) ?? throw new ContentNotFound;
            $this->check->version($version, $was->version, $was->updatedBy, $was->updatedAt, $store);

            if ($was->status === Publication::Published) {
                return;
            }

            $now = Moments::now();
            $document = $this->with($was, Publication::Published, $now, $staffId, $now);
            $this->requirePublishable($document);
            $this->marks->showing($kind->value, $id, $read);
            // Only the state changes: its words, images and photos are left as they are.
            $this->documents->updateRow($document);
            $this->platform->recordAudit(ContentAudit::entry("content.{$kind->key()}.published", "content.{$kind->key()}", $id, $store->id, AuditChanges::none()->changed('status', $was->status->value, Publication::Published->value)));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * @throws ContentError
     */
    public function unpublish(DocumentKind $kind, string $id, int $version, StoreDto $store, ?string $staffId): void
    {
        $this->db->transaction(function () use ($kind, $id, $version, $store, $staffId): void {
            $was = $this->documents->lock($kind, $id) ?? throw new ContentNotFound;
            $this->check->version($version, $was->version, $was->updatedBy, $was->updatedAt, $store);

            if ($was->status === Publication::Draft) {
                return;
            }

            $this->documents->updateRow($this->with($was, Publication::Draft, null, $staffId, Moments::now()));
            $this->platform->recordAudit(ContentAudit::entry("content.{$kind->key()}.unpublished", "content.{$kind->key()}", $id, $store->id, AuditChanges::none()->changed('status', $was->status->value, Publication::Draft->value)));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * @throws ContentError
     */
    public function delete(DocumentKind $kind, string $id, StoreDto $store): void
    {
        $this->db->transaction(function () use ($kind, $id, $store): void {
            // Its addresses are freed below: the store's address lock first, then the row.
            $this->check->lockAddresses($store->id);
            $was = $this->documents->lock($kind, $id) ?? throw new ContentNotFound;

            if ($was->status !== Publication::Draft) {
                throw new NotDeletable;
            }

            $this->documents->delete($kind, $was->id);
            // A deleted item's addresses are free again (§1.1 rule 11).
            $this->history->forget($kind->value, $was->id);
            $this->marks->forget($kind->value, $was->id);
            $this->platform->recordAudit(ContentAudit::entry("content.{$kind->key()}.deleted", "content.{$kind->key()}", $was->id, $store->id, self::changes($was, null)));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * Refuses what a published item may not be (§1.1 rules 2 and 10).
     *
     * @throws ContentError
     */
    public function requirePublishable(Document $document): void
    {
        $ar = $document->text('ar') ?? throw new InvalidContentInput('title_ar', InvalidContentInput::REQUIRED);
        $en = $document->text('en') ?? throw new MissingEnglish('title');

        foreach (['ar' => $ar, 'en' => $en] as $locale => $text) {
            if ($text->body->isEmpty()) {
                throw $locale === 'ar' ? new InvalidContentInput('body_ar', InvalidContentInput::REQUIRED) : new MissingEnglish('body');
            }
        }

        Words::requireEnglish('seo_title', $ar->seoTitle, $en->seoTitle);
        Words::requireEnglish('seo_description', $ar->seoDescription, $en->seoDescription);
        Words::requireEnglish('city', $ar->city, $en->city);

        if ($document->kind === DocumentKind::Post) {
            self::requirePair('summary', $ar->summary, $en->summary);
            self::requirePair('cover_alt', $ar->coverAlt, $en->coverAlt);

            if ($document->coverMediaId === null) {
                throw new InvalidContentInput('cover', InvalidContentInput::REQUIRED);
            }
        }

        if ($document->kind === DocumentKind::Project && $document->photos === []) {
            throw new InvalidContentInput('photos', InvalidContentInput::TOO_FEW, values: ['min' => 1]);
        }

        $this->images->requireReady($document->images());
    }

    /**
     * What the form sent, read and checked before anything is locked.
     *
     * @return array{texts: array<string, DocumentText>, share: array{ar: string|null, en: string|null}, category: string|null, cover: string|null, products: list<string>, photos: list<string>}
     *
     * @throws ContentError
     */
    private function read(DocumentKind $kind, DocumentInput $input, StoreDto $store, ?Document $was): array
    {
        $title = Words::arabic('title', $input->titleAr, $input->titleEn, 150);
        $seoTitle = Words::pair('seo_title', $input->seoTitleAr, $input->seoTitleEn, 70);
        $seoDescription = Words::pair('seo_description', $input->seoDescriptionAr, $input->seoDescriptionEn, 160);
        $summary = $kind === DocumentKind::Post ? Words::pair('summary', $input->summaryAr, $input->summaryEn, 200) : ['ar' => null, 'en' => null];
        $coverAlt = $kind === DocumentKind::Post ? Words::pair('cover_alt', $input->coverAltAr, $input->coverAltEn, 200) : ['ar' => null, 'en' => null];
        $city = $kind === DocumentKind::Project ? Words::pair('city', $input->cityAr, $input->cityEn, 100) : ['ar' => null, 'en' => null];

        $bodies = ['ar' => $input->bodyAr, 'en' => $input->bodyEn];
        $sentImages = ['ar' => $input->imagesAr, 'en' => $input->imagesEn];
        $slugs = ['ar' => $input->slugAr, 'en' => $input->slugEn];
        $texts = [];

        foreach (['ar', 'en'] as $locale) {
            if ($title[$locale] === null) {
                // An English side is its title first: a body or an address typed without one is refused on it.
                if (trim((string) $bodies[$locale]) !== '' || trim((string) $slugs[$locale]) !== '') {
                    throw new InvalidContentInput("title_{$locale}", InvalidContentInput::REQUIRED);
                }

                continue;
            }

            $body = trim((string) $bodies[$locale]) === '' ? RichText::empty() : MarkText::blocks("body_{$locale}", (string) $bodies[$locale]);
            $texts[$locale] = new DocumentText(
                $title[$locale],
                Slug::typedOrFrom("slug_{$locale}", $locale, $slugs[$locale], $title[$locale])->value,
                $body,
                $seoTitle[$locale],
                $seoDescription[$locale],
                $summary[$locale],
                $coverAlt[$locale],
                $city[$locale],
                $this->bodyImages($locale, $body, $sentImages[$locale]),
            );
        }

        $share = $this->images->files(['share_image_ar' => $input->shareAr, 'share_image_en' => $input->shareEn]);

        return [
            'texts' => $texts,
            'share' => ['ar' => $share['share_image_ar'], 'en' => $share['share_image_en']],
            'category' => $kind === DocumentKind::Post ? $this->category($input->categoryId, $store) : null,
            'cover' => $kind === DocumentKind::Post ? $this->images->files(['cover' => $input->coverMediaId])['cover'] : null,
            'products' => $this->products($kind, $input->products, $was->products ?? [], $store),
            'photos' => $kind === DocumentKind::Project ? $this->photos($input->photos) : [],
        ];
    }

    /**
     * The body's image rows: every ref it names given its file, each a public image; the description is the block's.
     *
     * @param  array<int, string>  $sent
     * @return array<int, array{media: string, alt: string}>
     *
     * @throws InvalidContentInput
     */
    private function bodyImages(string $locale, RichText $body, array $sent): array
    {
        $alts = [];

        foreach ($body->toArray()['blocks'] as $block) {
            if (($block['type'] ?? null) === 'image' && is_int($block['ref'] ?? null) && is_string($block['alt'] ?? null)) {
                $alts[$block['ref']] ??= $block['alt'];
            }
        }

        $files = [];

        foreach (array_keys($alts) as $ref) {
            $files["body_image_{$locale}#{$ref}"] = $sent[$ref] ?? throw new InvalidContentInput("body_{$locale}", InvalidContentInput::IMAGE_REF, values: ['ref' => $ref]);
        }

        $checked = $this->images->files($files);
        $rows = [];

        foreach ($alts as $ref => $alt) {
            $rows[$ref] = ['media' => (string) $checked["body_image_{$locale}#{$ref}"], 'alt' => $alt];
        }

        return $rows;
    }

    /**
     * @throws InvalidContentInput
     */
    private function category(?string $categoryId, StoreDto $store): ?string
    {
        if ($categoryId === null || trim($categoryId) === '') {
            return null;
        }

        $category = $this->categories->find(trim($categoryId));

        // Another store's category is no category of this post's.
        if ($category === null || $category->storeId !== $store->id) {
            throw new InvalidContentInput('category', InvalidContentInput::CATEGORY);
        }

        return $category->id;
    }

    /**
     * Linked products: at most the kind's number, each once. **A product newly linked is one the store's search offers**
     * (§1.12, §1.13: the store's ready products with a size switched on there - Catalog's `readySwitchedOn`, the read Add
     * Product searches with; PR 7b's review): never another store's, nor one switched off here, by its id typed in. **A
     * product linked before is kept whatever it is now** - archived, back to a draft, switched off here: the shop shows
     * only what it may (§1.1 rule 17: it "vanishes from the shop and stays listed in the panel"), so a save of the
     * item's other fields is never refused for it. One deleted since is no longer listed in the panel, and the next save
     * drops it - only it, the rest kept in their order.
     *
     * @param  list<string>  $sent
     * @param  list<string>  $before
     * @return list<string>
     *
     * @throws InvalidContentInput
     */
    private function products(DocumentKind $kind, array $sent, array $before, StoreDto $store): array
    {
        $ids = array_map(static fn (string $id): string => strtolower(trim($id)), $sent);

        if (count($ids) > $kind->maxProducts()) {
            throw new InvalidContentInput('products', InvalidContentInput::TOO_MANY, values: ['max' => $kind->maxProducts()]);
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidContentInput('products', InvalidContentInput::DUPLICATE);
        }

        $added = array_values(array_diff($ids, array_map(strtolower(...), $before)));

        if ($added !== []) {
            $offered = array_map(static fn (FoundProductDto $product): string => strtolower($product->productId), $this->catalog->searchProducts(StoreId::fromString($store->id), null, true, $added, null, $kind->maxProducts())->rows);

            if (array_diff($added, $offered) !== []) {
                throw new InvalidContentInput('products', InvalidContentInput::PRODUCT);
            }
        }

        // A product kept from before stays whatever it is now, unless Catalog no longer has it: deleted since, it
        // goes on this save, and only it (PR 8's second review).
        $kept = array_values(array_diff($ids, $added));

        if ($kept === []) {
            return $ids;
        }

        $known = array_map(strtolower(...), array_map('strval', array_keys($this->catalog->products($kept))));

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $added, true) || in_array($id, $known, true)));
    }

    /**
     * A project's photos, in order: at most 20, each a public image, each once.
     *
     * @param  list<string>  $sent
     * @return list<string>
     *
     * @throws InvalidContentInput
     */
    private function photos(array $sent): array
    {
        if (count($sent) > 20) {
            throw new InvalidContentInput('photos', InvalidContentInput::TOO_MANY, values: ['max' => 20]);
        }

        $boxes = [];

        foreach ($sent as $index => $id) {
            $boxes['photo#'.($index + 1)] = $id;
        }

        $checked = array_values(array_filter($this->images->files($boxes), static fn (?string $id): bool => $id !== null));

        if (count(array_unique($checked)) !== count($checked)) {
            throw new InvalidContentInput('photos', InvalidContentInput::DUPLICATE);
        }

        return $checked;
    }

    /**
     * @param  array{texts: array<string, DocumentText>, share: array{ar: string|null, en: string|null}, category: string|null, cover: string|null, products: list<string>, photos: list<string>}  $read
     */
    private function document(string $id, DocumentKind $kind, StoreDto $store, Publication $status, ?DateTimeImmutable $publishedAt, array $read, int $version, ?string $staffId, DateTimeImmutable $now): Document
    {
        return new Document($id, $kind, $store->id, $status, $publishedAt, $read['share']['ar'], $read['share']['en'], $read['category'], $read['cover'], $read['texts'], $read['products'], $read['photos'], $version, $staffId, $now);
    }

    private function with(Document $was, Publication $status, ?DateTimeImmutable $publishedAt, ?string $staffId, DateTimeImmutable $now): Document
    {
        return new Document($was->id, $was->kind, $was->storeId, $status, $publishedAt, $was->shareAr, $was->shareEn, $was->categoryId, $was->coverMediaId, $was->texts, $was->products, $was->photos, $was->version + 1, $staffId, $now);
    }

    /**
     * @return array<string, string>
     */
    private static function slugs(Document $document): array
    {
        return array_map(static fn (DocumentText $text): string => $text->slug, $document->texts);
    }

    /**
     * @throws ContentError
     */
    private static function requirePair(string $box, ?string $ar, ?string $en): void
    {
        if ($ar === null) {
            throw new InvalidContentInput("{$box}_ar", InvalidContentInput::REQUIRED);
        }

        Words::requireEnglish($box, $ar, $en);
    }

    /** Short fields by value; each body as changed, with its length (§1.1 rule 13). */
    private static function changes(?Document $before, ?Document $after): AuditChanges
    {
        $changes = ContentAudit::diff($before?->fields() ?? [], $after?->fields() ?? []);

        foreach (['ar', 'en'] as $locale) {
            ContentAudit::long($changes, "body_{$locale}", $before?->text($locale)?->body->toJson(), $after?->text($locale)?->body->toJson());
        }

        return $changes;
    }
}
