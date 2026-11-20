<?php

declare(strict_types=1);

namespace Modules\Content\Application\Policies;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
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
use Modules\Content\Domain\Exception\PolicyNotDeletable;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Model\Policy;
use Modules\Content\Domain\Model\PolicyKind;
use Modules\Content\Domain\Model\PolicyText;
use Modules\Content\Domain\Model\PolicyVersion;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Policies;
use Modules\Content\Domain\Repository\SlugHistory;
use Modules\Content\Domain\ValueObject\RichText;
use Modules\Content\Domain\ValueObject\Slug;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Policies, written (flags-2026-11.md S3.1, S3.2, S3.7; the owner's answers of 2026-11-20) - a page's rules
 * (`DocumentWriter`) over a list of stores, each called by its own handler under `content.policy.manage`:
 *
 * - **Saved with Arabic** (title; content.md §1.1 rule 2); an English side only with its title; a share image per
 *   language as a page's (§1.14). An address per language made from the title until typed, the same in every store
 *   the policy serves and **unique per store and language** among policies, now or before (`SlugTaken`); every address
 *   it had is kept in each store it serves, so an old one forwards.
 * - **Its kind is fixed once made** (the builder's pick); **its stores are at least one**.
 * - **A save changes only the stores ticked** (owner, 2026-11-20). When what it says or shows changed - any language's
 *   title, address, body, body images or SEO boxes, or a share image (the builder's pick: every box of the form) - and
 *   a store it serves is not ticked, **it splits**: a new policy of the kind serves the stores ticked with the new
 *   words, published at its version 1 when the original was published (held to Publish's rules), else a draft; the
 *   original keeps its other stores with its words, state and versions, and its addresses in the stores that moved pass
 *   to the new one. Otherwise the one policy is changed: its stores become those ticked, plus those it serves that the
 *   person may not work in (not on their form); **a store unticked with the words unchanged is taken off** (the
 *   builder's pick), its addresses there freed.
 * - **Published at once** with both languages' title and body, the SEO boxes' English, every image ready, and **no
 *   other published policy of its kind in any of its stores** (Other excepted). A published policy stays held to those
 *   rules on every save, a store added to it included.
 * - **Versions** (F14): a publish whose text (each language's title, body and body images) differs from the last
 *   version kept - the first publish included - raises `text_version` by one and keeps the text as that version. A save
 *   of a published policy changes the live text at once, so a changed text there is a new version too (the builder's
 *   pick). Each version is read by its number (`Policies::version`).
 * - **Deleted only if never published** (owner, 2026-11-20: `text_version` 0), its addresses freed; once published it is
 *   only unpublished, so every version stays (`PolicyNotDeletable`).
 * - **Two people**: every change is at the version the form read (`StaleEdit`); all of them run under the policies'
 *   lock, taken first (`ContentLocks::POLICIES`).
 * - Audited by value, with no store (it serves several, as a campaign's shared fields); a body as changed with its
 *   length. A split is `content.policy.split` on the original and `content.policy.created` on the new one, each naming
 *   the other.
 */
final readonly class PolicyWriter
{
    /** The kind its addresses and Translate's marks are kept under (slug_history, unread_translations). */
    public const string KIND = 'POLICY';

    public const int MAX_TITLE = 150;

    public function __construct(
        private Policies $policies,
        private PolicyRules $rules,
        private ContentImages $images,
        private EditCheck $check,
        private SlugHistory $history,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private TranslationMarks $marks,
    ) {}

    /**
     * @param  list<StoreDto>  $stores  the stores ticked, each checked, in id order
     * @return string the id of the policy now serving the stores ticked: a new one when the save split it
     *
     * @throws ContentError|Unauthorized
     */
    public function save(PolicyInput $input, array $stores, ?string $staffId): string
    {
        $kind = self::kind($input->kind);
        $read = $this->read($input);
        $picked = array_map(static fn (StoreDto $store): string => $store->id, $stores);

        return $this->db->transaction(function () use ($input, $kind, $read, $stores, $picked, $staffId): string {
            $now = Moments::now();
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::POLICIES);

            if ($input->id === null) {
                return $this->create(strtolower((string) Str::ulid()), $kind, $picked, $read, $input, $staffId, $now);
            }

            $was = $this->policies->lock($input->id) ?? throw new ContentNotFound;
            $this->rules->served($was);
            $this->check->version($input->version, $was->version, $was->updatedBy, $was->updatedAt, $stores[0]);

            if ($kind !== $was->kind) {
                throw new InvalidContentInput('kind', InvalidContentInput::KIND_FIXED);
            }

            $asked = new Policy($was->id, $was->kind, $was->stores, $was->status, $was->publishedAt, $read['texts'], $read['share']['ar'], $read['share']['en'], $was->textVersion, $was->version, $staffId, $now);
            $untouched = array_values(array_diff($was->stores, $picked));

            if ($untouched !== [] && ! $was->sameContentAs($asked)) {
                return $this->split($was, $asked, $picked, $untouched, $input, $staffId, $now);
            }

            // The one policy changed. Its words changed only with every store it serves ticked; unchanged, a store unticked
            // is taken off when the person may work in it (else it is not on their form, and stays).
            $removed = $this->rules->pickable($untouched);
            $this->rules->authorizeEach($removed);
            $storeIds = array_values(array_unique([...array_diff($was->stores, $removed), ...$picked]));
            sort($storeIds);
            $policy = $this->with($asked, $was->status, $was->publishedAt, $was->textVersion, $staffId, $now, $storeIds);
            $kept = null;

            if ($was->isPublished()) {
                // A published policy shows what is saved at once: held to Publish's rules, and a changed text is a new version.
                [$number, $isNew] = $this->textNumber($policy);
                $policy = $this->with($policy, $policy->status, $policy->publishedAt, $number, $staffId, $now, $storeIds, false);
                $this->requirePublishable($policy);
                $kept = $isNew ? PolicyVersion::of($policy, $number, $now, $staffId) : null;
            }

            $this->history->forgetIn(self::KIND, $policy->id, $removed);
            $this->addresses($policy);
            $this->policies->update($policy);

            if ($kept !== null) {
                $this->policies->addVersion($kept);
            }

            $this->marks->saved(self::KIND, $policy->id, $policy->stores[0], $input->translated, $input->read, $was->isPublished());
            $this->platform->recordAudit(ContentAudit::entry('content.policy.changed', 'content.policy', $policy->id, null, self::changes($was, $policy)));
            $this->cache->invalidate();

            return $policy->id;
        }, 3);
    }

    /**
     * @param  list<string>  $checked  the stores the policy served when found, each checked
     *
     * @throws ContentError|Unauthorized
     */
    public function publish(string $id, int $version, array $checked, ?string $staffId, bool $read = false): void
    {
        $this->db->transaction(function () use ($id, $version, $checked, $staffId, $read): void {
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::POLICIES);
            $was = $this->policies->lock($id) ?? throw new ContentNotFound;
            $this->rules->stillMay($was, $checked);
            $this->check->version($version, $was->version, $was->updatedBy, $was->updatedAt, $this->firstStore($was));

            if ($was->isPublished()) {
                return;
            }

            $now = Moments::now();
            [$number, $isNew] = $this->textNumber($was);
            $policy = $this->with($was, Publication::Published, $now, $number, $staffId, $now);
            $this->requirePublishable($policy);
            $this->marks->showing(self::KIND, $was->id, $read);
            // Only the state and the version's number change: its words, images and stores are left as they are.
            $this->policies->updateRow($policy);

            if ($isNew) {
                $this->policies->addVersion(PolicyVersion::of($policy, $number, $now, $staffId));
            }

            $changes = AuditChanges::none()->changed('status', $was->status->value, Publication::Published->value);

            if ($policy->textVersion !== $was->textVersion) {
                $changes->changed('text_version', $was->textVersion, $policy->textVersion);
            }

            $this->platform->recordAudit(ContentAudit::entry('content.policy.published', 'content.policy', $was->id, null, $changes));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * @param  list<string>  $checked  the stores the policy served when found, each checked
     *
     * @throws ContentError|Unauthorized
     */
    public function unpublish(string $id, int $version, array $checked, ?string $staffId): void
    {
        $this->db->transaction(function () use ($id, $version, $checked, $staffId): void {
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::POLICIES);
            $was = $this->policies->lock($id) ?? throw new ContentNotFound;
            $this->rules->stillMay($was, $checked);
            $this->check->version($version, $was->version, $was->updatedBy, $was->updatedAt, $this->firstStore($was));

            if (! $was->isPublished()) {
                return;
            }

            $this->policies->updateRow($this->with($was, Publication::Draft, null, $was->textVersion, $staffId, Moments::now()));
            $this->platform->recordAudit(ContentAudit::entry('content.policy.unpublished', 'content.policy', $was->id, null, AuditChanges::none()->changed('status', $was->status->value, Publication::Draft->value)));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * @param  list<string>  $checked  the stores the policy served when found, each checked
     *
     * @throws ContentError|Unauthorized
     */
    public function delete(string $id, int $version, array $checked): void
    {
        $this->db->transaction(function () use ($id, $version, $checked): void {
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::POLICIES);
            $was = $this->policies->lock($id) ?? throw new ContentNotFound;
            $this->rules->stillMay($was, $checked);
            $this->check->version($version, $was->version, $was->updatedBy, $was->updatedAt, $this->firstStore($was));

            // Once published, its versions are what customers accepted: it is only ever unpublished (owner, 2026-11-20).
            if ($was->wasPublished()) {
                throw new PolicyNotDeletable;
            }

            $this->policies->delete($was->id);
            // A deleted item's addresses are free again (content.md §1.1 rule 11).
            $this->history->forget(self::KIND, $was->id);
            $this->marks->forget(self::KIND, $was->id);
            $this->platform->recordAudit(ContentAudit::entry('content.policy.deleted', 'content.policy', $was->id, null, self::changes($was, null)));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * Refuses what a published policy may not be: both languages' title and body, the SEO boxes' English, every image
     * ready, and no other published policy of its kind in its stores.
     *
     * @throws ContentError
     */
    public function requirePublishable(Policy $policy): void
    {
        $ar = $policy->text('ar') ?? throw new InvalidContentInput('title_ar', InvalidContentInput::REQUIRED);
        $en = $policy->text('en') ?? throw new MissingEnglish('title');

        foreach (['ar' => $ar, 'en' => $en] as $locale => $text) {
            if ($text->body->isEmpty()) {
                throw $locale === 'ar' ? new InvalidContentInput('body_ar', InvalidContentInput::REQUIRED) : new MissingEnglish('body');
            }
        }

        Words::requireEnglish('seo_title', $ar->seoTitle, $en->seoTitle);
        Words::requireEnglish('seo_description', $ar->seoDescription, $en->seoDescription);
        $this->images->requireReady($policy->images());
        $this->rules->requireOnlyOfKind($policy);
    }

    /**
     * A new policy: a draft (`create`), or the copy a split makes (`split`), published at its version 1 when the
     * original was published.
     *
     * @param  list<string>  $storeIds
     * @param  array{texts: array<string, PolicyText>, share: array{ar: string|null, en: string|null}}  $read
     *
     * @throws ContentError
     */
    private function create(string $id, PolicyKind $kind, array $storeIds, array $read, PolicyInput $input, ?string $staffId, DateTimeImmutable $now, bool $published = false, ?AuditChanges $changes = null): string
    {
        $policy = new Policy($id, $kind, $storeIds, $published ? Publication::Published : Publication::Draft, $published ? $now : null, $read['texts'], $read['share']['ar'], $read['share']['en'], $published ? 1 : 0, 1, $staffId, $now);

        if ($published) {
            $this->requirePublishable($policy);
        }

        $this->addresses($policy);
        $this->policies->add($policy, $staffId);

        if ($published) {
            $this->policies->addVersion(PolicyVersion::of($policy, 1, $now, $staffId));
        }

        $this->marks->saved(self::KIND, $policy->id, $storeIds[0], $input->translated, $input->read, $published);
        $this->platform->recordAudit(ContentAudit::entry('content.policy.created', 'content.policy', $policy->id, null, self::changes(null, $policy, $changes)));
        $this->cache->invalidate();

        return $policy->id;
    }

    /**
     * The stores ticked take the new words as a policy of their own; the original keeps the rest, as it was.
     *
     * @param  list<string>  $picked  the stores ticked, each checked
     * @param  list<string>  $untouched  the stores it serves that were not ticked: at least one
     *
     * @throws ContentError
     */
    private function split(Policy $was, Policy $asked, array $picked, array $untouched, PolicyInput $input, ?string $staffId, DateTimeImmutable $now): string
    {
        $copyId = strtolower((string) Str::ulid());
        $original = $this->with($was, $was->status, $was->publishedAt, $was->textVersion, $staffId, $now, $untouched);
        // The original first, so the copy's stores are no longer its own when the copy is checked against its kind.
        $this->policies->update($original);
        $this->history->handOver(self::KIND, $was->id, $copyId, array_values(array_intersect($picked, $was->stores)));
        $this->create($copyId, $was->kind, $picked, ['texts' => $asked->texts, 'share' => ['ar' => $asked->shareAr, 'en' => $asked->shareEn]], $input, $staffId, $now, $was->isPublished(), AuditChanges::none()->changed('split_from', null, $was->id));
        $this->platform->recordAudit(ContentAudit::entry('content.policy.split', 'content.policy', $was->id, null, ContentAudit::diff(['stores' => implode(',', $was->stores)], ['stores' => implode(',', $untouched)])->changed('split_to', null, $copyId)));

        return $copyId;
    }

    /**
     * The number the policy's text publishes as: the last version's when the text is that version's, else the next;
     * and whether it is a new version to keep.
     *
     * @return array{0: int, 1: bool}
     */
    private function textNumber(Policy $policy): array
    {
        $last = $this->policies->latestVersion($policy->id);

        if ($last !== null && $last->sameTextAs($policy)) {
            return [$last->number, false];
        }

        return [($last->number ?? 0) + 1, true];
    }

    /**
     * Claims the policy's addresses under the policies' lock, in each store it serves: each refused when another policy
     * holds it there, now or before; then kept as its own, the history's unique index the last line.
     *
     * @throws SlugTaken
     */
    private function addresses(Policy $policy): void
    {
        foreach ($policy->stores as $storeId) {
            foreach ($policy->texts as $locale => $text) {
                $holder = $this->history->holder($storeId, self::KIND, $locale, $text->slug);

                if ($holder !== null && $holder !== $policy->id) {
                    throw new SlugTaken($locale, $text->slug);
                }
            }
        }

        foreach ($policy->stores as $storeId) {
            foreach ($policy->texts as $locale => $text) {
                $this->history->claim($storeId, self::KIND, $policy->id, $locale, $text->slug);
            }
        }
    }

    /**
     * The form's words and share images, read and checked before anything is locked.
     *
     * @return array{texts: array<string, PolicyText>, share: array{ar: string|null, en: string|null}}
     *
     * @throws ContentError
     */
    private function read(PolicyInput $input): array
    {
        $title = Words::arabic('title', $input->titleAr, $input->titleEn, self::MAX_TITLE);
        $seoTitle = Words::pair('seo_title', $input->seoTitleAr, $input->seoTitleEn, 70);
        $seoDescription = Words::pair('seo_description', $input->seoDescriptionAr, $input->seoDescriptionEn, 160);
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
            $texts[$locale] = new PolicyText(
                $title[$locale],
                Slug::typedOrFrom("slug_{$locale}", $locale, $slugs[$locale], $title[$locale])->value,
                $body,
                $seoTitle[$locale],
                $seoDescription[$locale],
                $this->bodyImages($locale, $body, $sentImages[$locale]),
            );
        }

        $share = $this->images->files(['share_image_ar' => $input->shareAr, 'share_image_en' => $input->shareEn]);

        return ['texts' => $texts, 'share' => ['ar' => $share['share_image_ar'], 'en' => $share['share_image_en']]];
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

        ksort($rows);

        return $rows;
    }

    /**
     * @throws InvalidContentInput
     */
    private static function kind(?string $kind): PolicyKind
    {
        return PolicyKind::tryFrom(strtoupper(trim((string) $kind))) ?? throw new InvalidContentInput('kind', InvalidContentInput::CHOICE);
    }

    /**
     * The store a refusal over a newer version tells the time in: the policy's first.
     *
     * @throws ContentNotFound
     */
    private function firstStore(Policy $policy): StoreDto
    {
        foreach ($policy->stores as $storeId) {
            $store = $this->platform->store(StoreId::fromString($storeId));

            if ($store !== null) {
                return $store;
            }
        }

        throw new ContentNotFound;
    }

    /**
     * @param  list<string>|null  $stores  its stores, when they change
     */
    private function with(Policy $was, Publication $status, ?DateTimeImmutable $publishedAt, int $textVersion, ?string $staffId, DateTimeImmutable $now, ?array $stores = null, bool $newVersion = true): Policy
    {
        return new Policy($was->id, $was->kind, $stores ?? $was->stores, $status, $publishedAt, $was->texts, $was->shareAr, $was->shareEn, $textVersion, $newVersion ? $was->version + 1 : $was->version, $staffId, $now);
    }

    /** Short fields by value; each body as changed, with its length (content.md §1.1 rule 13). */
    private static function changes(?Policy $before, ?Policy $after, ?AuditChanges $changes = null): AuditChanges
    {
        $changes = ContentAudit::diff($before?->fields() ?? [], $after?->fields() ?? [], changes: $changes);

        foreach (['ar', 'en'] as $locale) {
            ContentAudit::long($changes, "body_{$locale}", $before?->text($locale)?->body->toJson(), $after?->text($locale)?->body->toJson());
        }

        return $changes;
    }
}
