<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\ThemeClash;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Model\ThemePart;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * What a theme's use cases share (content.md §1.8): its stores (a Super Admin's, checked in each), its 11 parts as
 * typed - **artwork and words only, never a colour** (Q7 (a)) - and what Schedule asks: English beside every Arabic,
 * every image's sizes ready, an end still to come in each store, and **no other theme of a store sharing a moment
 * with it**, checked under each store's theme lock, in store-id order, so of two racing one wins (`ThemeClash`).
 */
final readonly class ThemeRules
{
    /** Transparent parts take PNG or WebP: Platform's JPEG sizes turn transparency white (§1.8). */
    private const array TRANSPARENT = ['image/png', 'image/webp'];

    public const int MAX_EXTRA = 80;

    public const int MAX_PROMISES = 4;

    public function __construct(
        private ContentAccess $access,
        private Themes $themes,
        private ContentLocks $locks,
        private ContentImages $images,
        private PlatformApi $platform,
    ) {}

    /**
     * The stores a theme picks, each authorized for the reserved job, in store-id order.
     *
     * @param  list<string>  $storeIds
     * @return list<StoreDto>
     *
     * @throws ContentError
     */
    public function stores(array $storeIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $storeIds)));

        if ($ids === []) {
            throw new InvalidContentInput('stores', InvalidContentInput::TOO_FEW, values: ['min' => 1]);
        }

        sort($ids);

        return array_map(fn (string $id): StoreDto => $this->access->authorize(ContentPermissions::THEME_MANAGE, $id), $ids);
    }

    /**
     * Each store's moments of a typed start and end, read on that store's own clock (Q1 (a)).
     *
     * @param  list<StoreDto>  $stores
     * @return array<string, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     *
     * @throws InvalidContentInput
     */
    public static function moments(string $starts, string $ends, array $stores): array
    {
        $moments = [];

        foreach ($stores as $store) {
            $moments[$store->id] = Moments::onStoreClock($starts, $ends, $store);
        }

        return $moments;
    }

    /**
     * The parts as typed, each refused unless it holds what its part takes: words of its length per language (Arabic
     * beside any English), a link only on the announcement strip and the cart message, the promises' words, images in
     * its slots - per language for artwork with words on it - each a public image of the library, a transparent part's
     * a PNG or WebP. A part left empty is left out.
     *
     * @param  list<array<string, mixed>>  $typed  `{part, textAr?, textEn?, link?, extras?: {promise_1: {ar, en}}, images?: {"SLOT:locale": mediaId}}`
     * @return list<ThemePart>
     *
     * @throws ContentError
     */
    public function parts(array $typed): array
    {
        $parts = [];

        foreach ($typed as $input) {
            $kind = ThemePartKind::tryFrom(is_string($input['part'] ?? null) ? $input['part'] : '') ?? throw new InvalidContentInput('parts', InvalidContentInput::CHOICE);

            if (isset($parts[$kind->value])) {
                throw new InvalidContentInput('parts', InvalidContentInput::DUPLICATE);
            }

            $attribute = 'part_'.strtolower($kind->value);
            $text = $kind->maxText() === 0
                ? ['ar' => null, 'en' => null]
                : Words::pair($attribute, self::string($input['textAr'] ?? null), self::string($input['textEn'] ?? null), $kind->maxText());
            $link = $kind->takesLink() ? Words::link("{$attribute}_link", self::string($input['link'] ?? null)) : null;

            if ($link !== null && $text['ar'] === null) {
                throw new InvalidContentInput("{$attribute}_ar", InvalidContentInput::REQUIRED);
            }

            $parts[$kind->value] = new ThemePart($kind, $text['ar'], $text['en'], $link, $this->extras($kind, $input['extras'] ?? []), $this->images($kind, $input['images'] ?? []));
        }

        return array_values(array_filter($parts, static fn (ThemePart $part): bool => ! $part->isEmpty()));
    }

    /**
     * Schedule's rules, the clash among them (§1.8, §1.1 rules 2 and 10). Call after `lockStores()`.
     *
     * @param  list<ThemePart>  $parts
     * @param  array<string, StoreDto>  $stores  by id
     *
     * @throws ContentError
     */
    public function requireSchedulable(Theme $theme, array $parts, array $stores, DateTimeImmutable $now): void
    {
        Words::requireEnglish('name', $theme->nameAr, $theme->nameEn);

        foreach ($parts as $part) {
            $attribute = 'part_'.strtolower($part->part->value);
            Words::requireEnglish($attribute, $part->textAr, $part->textEn);

            foreach ($part->extras as $key => $words) {
                Words::requireEnglish("{$attribute}_{$key}", $words['ar'], $words['en']);
            }

            if ($part->part->imagesPerLanguage()) {
                foreach ($part->part->slots() as $slot) {
                    if (isset($part->images["{$slot}:ar"]) !== isset($part->images["{$slot}:en"])) {
                        throw new InvalidContentInput("{$attribute}_".strtolower($slot).'_en', InvalidContentInput::REQUIRED);
                    }
                }
            }

            // Each named as its part (`box#slot`, ContentImages names a box by what comes before the mark).
            $boxes = [];

            foreach ($part->images as $key => $mediaId) {
                $boxes["{$attribute}#{$key}"] = $mediaId;
            }

            $this->images->requireReady($boxes);
        }

        foreach ($theme->stores as $storeId => [, $ends]) {
            if ($ends <= $now) {
                throw new InvalidContentInput('ends_at', InvalidContentInput::PAST);
            }

            $this->requireNoClash($theme, $stores[$storeId] ?? throw new InvalidContentInput('stores', InvalidContentInput::STORE));
        }
    }

    /**
     * Every store's theme lock, in store-id order, first in the transaction (one lock order).
     *
     * @param  list<string>  $storeIds
     */
    public function lockStores(array $storeIds): void
    {
        $ids = array_values(array_unique(array_map(strtolower(...), $storeIds)));
        sort($ids);

        foreach ($ids as $id) {
            $this->locks->lock($id, ContentLocks::THEMES);
        }
    }

    /**
     * A Latin address for `data-campaign`, made once from the English name (or the id), unique; never `base`.
     */
    public function slug(?string $nameEn, string $id): string
    {
        $base = substr(Str::slug((string) $nameEn), 0, 48);
        $base = $base === '' || $base === 'base' ? 'theme-'.substr($id, -8) : $base;

        for ($n = 1; $n <= 20; $n++) {
            $slug = $n === 1 ? $base : "{$base}-{$n}";

            if (! $this->themes->slugTaken($slug)) {
                return $slug;
            }
        }

        return 'theme-'.$id;
    }

    /**
     * @throws ThemeClash
     */
    private function requireNoClash(Theme $theme, StoreDto $store): void
    {
        $mine = $theme->scheduleIn($store->id);

        foreach ($this->themes->scheduledIn($store->id) as $other) {
            $theirs = $other->scheduleIn($store->id);

            if ($other->id !== $theme->id && $mine !== null && $theirs !== null && $theirs->overlaps($mine)) {
                throw new ThemeClash(
                    $other->nameEn !== null && app()->getLocale() === 'en' ? $other->nameEn : $other->nameAr,
                    $store->name->in(app()->getLocale()),
                    $theirs->startsAt === null ? '-' : Moments::shown($theirs->startsAt, $store),
                    $theirs->endsAt === null ? '-' : Moments::shown($theirs->endsAt, $store),
                );
            }
        }
    }

    /**
     * The promises row's words, one per promise (§1.8 part 3); no other part takes any.
     *
     * @return array<string, array{ar: string, en: string|null}>
     *
     * @throws InvalidContentInput
     */
    private function extras(ThemePartKind $kind, mixed $typed): array
    {
        if (! is_array($typed) || $typed === []) {
            return [];
        }

        if ($kind !== ThemePartKind::Promises || count($typed) > self::MAX_PROMISES) {
            throw new InvalidContentInput('part_'.strtolower($kind->value), InvalidContentInput::TOO_MANY, values: ['max' => $kind === ThemePartKind::Promises ? self::MAX_PROMISES : 0]);
        }

        $extras = [];

        foreach (array_values($typed) as $n => $words) {
            $key = 'promise_'.($n + 1);
            $pair = Words::pair("part_promises_{$key}", is_array($words) ? self::string($words['ar'] ?? null) : null, is_array($words) ? self::string($words['en'] ?? null) : null, self::MAX_EXTRA);

            if ($pair['ar'] !== null) {
                $extras[$key] = ['ar' => $pair['ar'], 'en' => $pair['en']];
            }
        }

        return $extras;
    }

    /**
     * @return array<string, string> `{SLOT}:{locale}` => media id
     *
     * @throws InvalidContentInput
     */
    private function images(ThemePartKind $kind, mixed $typed): array
    {
        if (! is_array($typed) || $typed === []) {
            return [];
        }

        $locales = $kind->imagesPerLanguage() ? ['ar', 'en'] : ['any'];
        $boxes = [];

        foreach ($typed as $key => $mediaId) {
            [$slot, $locale] = array_pad(explode(':', (string) $key, 2), 2, '');

            if (! in_array($slot, $kind->slots(), true) || ! in_array($locale, $locales, true) || ! is_string($mediaId) || trim($mediaId) === '') {
                throw new InvalidContentInput('part_'.strtolower($kind->value), InvalidContentInput::CHOICE);
            }

            $boxes['part_'.strtolower($kind->value)."#{$slot}:{$locale}"] = strtolower(trim($mediaId));
        }

        $files = [];

        foreach ($this->images->files($boxes) as $box => $mediaId) {
            if ($mediaId !== null) {
                $files[explode('#', $box, 2)[1]] = $mediaId;
            }
        }

        if ($kind->isTransparent() && $files !== []) {
            foreach ($this->platform->mediaOf(array_values($files)) as $media) {
                if (! in_array($media->mime, self::TRANSPARENT, true)) {
                    throw new InvalidContentInput('part_'.strtolower($kind->value), InvalidContentInput::IMAGE_FILE);
                }
            }
        }

        return $files;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
