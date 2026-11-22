<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;
use LogicException;

/**
 * A policy (flags-2026-11.md S3.1, S3.2): one of a fixed list of kinds, serving a list of stores (one text for several),
 * Draft or Published at once as a page is, its words per language (Arabic always, English to publish) and its share
 * image per language (content.md §1.14, owner 2026-11-20). `textVersion` is the number of its published text (0 until
 * first published, F14); `version` is the edit lock (content.md §1.1 rule 12).
 *
 * Its own rules, refused here before the database's CHECKs (`policies_published`, `policies_text_version`,
 * `policies_version`) ever see a row: published exactly when it has a moment of publishing; a text version of 0 or
 * more, and 1 or more once published; an edit version of 1 or more; at least one store. Nothing a person types reaches
 * these - the use cases build each policy - so a breach is the code's own mistake (`LogicException`).
 */
final readonly class Policy
{
    /**
     * @param  list<string>  $stores  store ids, lower-cased, in id order; at least one
     * @param  array<string, PolicyText>  $texts  by language; `ar` always there
     */
    public function __construct(
        public string $id,
        public PolicyKind $kind,
        public array $stores,
        public Publication $status,
        public ?DateTimeImmutable $publishedAt,
        public array $texts,
        public ?string $shareAr,
        public ?string $shareEn,
        public int $textVersion,
        public int $version,
        public ?string $updatedBy,
        public ?DateTimeImmutable $updatedAt,
    ) {
        if (($status === Publication::Published) !== ($publishedAt !== null)) {
            throw new LogicException('A policy is published exactly when it has a moment of publishing.');
        }

        if ($textVersion < 0 || ($status === Publication::Published && $textVersion < 1)) {
            throw new LogicException('A published policy has a version of its text, 1 or more.');
        }

        if ($version < 1 || $stores === []) {
            throw new LogicException('A policy has an edit version of 1 or more, and at least one store.');
        }
    }

    public function text(string $locale): ?PolicyText
    {
        return $this->texts[$locale] ?? null;
    }

    public function isPublished(): bool
    {
        return $this->status === Publication::Published;
    }

    /** Whether it was ever published: then every version is kept, and it is never deleted (owner, 2026-11-20). */
    public function wasPublished(): bool
    {
        return $this->textVersion > 0;
    }

    /** Its title in a language, the Arabic when it has no English. */
    public function titleIn(string $locale): string
    {
        return ($this->texts[$locale] ?? $this->texts['ar'] ?? null)->title ?? '';
    }

    /**
     * Whether it says and shows what another says and shows: each language's title, address, body, body images and
     * SEO boxes, and the share images. Its stores, state and numbers are not compared.
     */
    public function sameContentAs(self $other): bool
    {
        if ($this->shareAr !== $other->shareAr || $this->shareEn !== $other->shareEn) {
            return false;
        }

        $locales = array_keys($this->texts);
        $otherLocales = array_keys($other->texts);
        sort($locales);
        sort($otherLocales);

        if ($locales !== $otherLocales) {
            return false;
        }

        foreach ($this->texts as $locale => $text) {
            if (! $text->sameAs($other->texts[$locale])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every file it shows, by its box: the share images and each language's body images.
     *
     * @return array<string, string|null>
     */
    public function images(): array
    {
        $images = ['share_image_ar' => $this->shareAr, 'share_image_en' => $this->shareEn];

        foreach ($this->texts as $locale => $text) {
            foreach ($text->images as $ref => $image) {
                $images["body_image_{$locale}#{$ref}"] = $image['media'];
            }
        }

        return $images;
    }

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        $fields = [
            'kind' => $this->kind->value,
            'stores' => implode(',', $this->stores),
            'status' => $this->status->value,
            'text_version' => $this->textVersion,
            'share_media_ar' => $this->shareAr,
            'share_media_en' => $this->shareEn,
        ];

        foreach (['ar', 'en'] as $locale) {
            $fields = [...$fields, ...($this->texts[$locale] ?? null)?->fields($locale) ?? []];
        }

        return $fields;
    }
}
