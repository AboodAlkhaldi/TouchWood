<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use DateTimeImmutable;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Model\BannerPlacement;

/**
 * A banner's artwork and scheduling rules (content.md §1.6, §1.1 rules 2 and 10):
 *
 * - **Which images a placement takes**: `HOME_HERO` a desktop one only, `HOME_PHONE` a phone one only, `LISTING`
 *   both. **Artwork with words on it takes an Arabic and an English image each**; artwork without words one image
 *   for both languages (`any`).
 * - **A draft may lack any of them.** Scheduling - and saving a banner already scheduled - needs every image its
 *   placement takes, each ready, both descriptions, and an end after the start and not already past.
 */
final readonly class BannerRules
{
    public function __construct(
        private ContentImages $images,
    ) {}

    /**
     * The images sent, `{DESKTOP|PHONE}:{any|ar|en}` => media id, checked: only the ones the placement takes, each
     * a public image.
     *
     * @param  array<string, string|null>  $sent
     * @return array<string, string>
     *
     * @throws InvalidContentInput
     */
    public function images(BannerPlacement $placement, bool $hasWords, array $sent): array
    {
        $allowed = self::slots($placement, $hasWords);
        $sent = array_filter($sent, static fn (?string $mediaId): bool => $mediaId !== null && trim($mediaId) !== '');
        $boxes = [];

        foreach ($sent as $slot => $mediaId) {
            if (! in_array($slot, $allowed, true)) {
                throw new InvalidContentInput('images', InvalidContentInput::CHOICE);
            }

            $boxes[self::box($slot)] = $mediaId;
        }

        $checked = $this->images->files($boxes);
        $images = [];

        foreach ($sent as $slot => $mediaId) {
            $id = $checked[self::box($slot)];

            if ($id !== null) {
                $images[$slot] = $id;
            }
        }

        ksort($images);

        return $images;
    }

    /**
     * Refuses what a scheduled banner may not be.
     *
     * @throws ContentError
     */
    public function requireSchedulable(Banner $banner, DateTimeImmutable $now): void
    {
        if ($banner->altAr === null) {
            throw new InvalidContentInput('alt_ar', InvalidContentInput::REQUIRED);
        }

        if ($banner->altEn === null) {
            throw new MissingEnglish('alt');
        }

        $boxes = [];

        foreach (self::slots($banner->placement, $banner->hasWords) as $slot) {
            $boxes[self::box($slot)] = $banner->images[$slot] ?? throw new InvalidContentInput(self::box($slot), InvalidContentInput::REQUIRED);
        }

        if ($banner->schedule->endsAt !== null && $banner->schedule->endsAt <= $now) {
            throw new InvalidContentInput('ends_at', InvalidContentInput::PAST);
        }

        $this->images->requireReady($boxes);
    }

    /**
     * @return list<string> the slots a placement's banner takes
     */
    public static function slots(BannerPlacement $placement, bool $hasWords): array
    {
        $locales = $hasWords ? ['ar', 'en'] : ['any'];
        $slots = [];

        foreach (['DESKTOP' => $placement->takesDesktop(), 'PHONE' => $placement->takesPhone()] as $kind => $takes) {
            foreach ($takes ? $locales : [] as $locale) {
                $slots[] = "{$kind}:{$locale}";
            }
        }

        return $slots;
    }

    /** The box a slot is in: `desktop_image`, `phone_image_ar`. */
    public static function box(string $slot): string
    {
        [$kind, $locale] = explode(':', $slot, 2);

        return strtolower($kind).'_image'.($locale === 'any' ? '' : "_{$locale}");
    }
}
