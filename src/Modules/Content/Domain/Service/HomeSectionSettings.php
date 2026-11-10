<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Service;

use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\HomeLogo;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\ValueObject\Words;

/**
 * What staff fill in each home section (content.md §1.4's table), read from what the form sends and kept as words
 * only - every pair `{"ar": ..., "en": ...}`, every link a path or an https:// address, nothing else:
 *
 * | Type | Settings |
 * |---|---|
 * | `HERO` | eyebrow, title, line; main and second button (text pair and link); up to 3 short facts |
 * | `PROMISES` | 2 to 4 promises, each an icon of the fixed list, a title and a line |
 * | `CATEGORIES` | heading; how many tiles, 4 to 12 (8) |
 * | `BEST_SELLERS` | heading; how many, 4 to 20 (8) |
 * | `PROJECTS` | heading |
 * | `AUDIENCE` | the individuals' and the companies' block: title, line, button text and link |
 * | `POINTS` | small heading, heading, line; the figures are read from the Points settings, never typed (Q16 (b)) |
 * | `CLIENT_LOGOS` | heading; the logos are files of their own (`HomeLogo`), 6 to 40 |
 *
 * **Saved with Arabic** wherever anything is typed; **shown only with English beside every Arabic** and the type's
 * own minimum (§1.1 rule 2: "a home section can't be shown without both").
 *
 * The lengths and the icon list are the build's picks, the design giving none (`[PICK]` in the PR).
 */
final class HomeSectionSettings
{
    /** The Lucide icons a promise may wear (§1.4: "a fixed list"). */
    public const array ICONS = ['truck', 'shield-check', 'badge-check', 'headset', 'wrench', 'package', 'credit-card', 'rotate-ccw', 'store', 'clock', 'award', 'leaf'];

    public const int MAX_FACTS = 3;

    public const int MIN_PROMISES = 2;

    public const int MAX_PROMISES = 4;

    public const int MIN_LOGOS = 6;

    public const int MAX_LOGOS = 40;

    /** @var array<string, array{0: int, 1: int, 2: int}> type => [min, max, default] of its count */
    public const array COUNTS = ['CATEGORIES' => [4, 12, 8], 'BEST_SELLERS' => [4, 20, 8]];

    /**
     * The settings a form sent, as they are kept.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws InvalidContentInput
     */
    public static function read(HomeSectionType $type, array $input): array
    {
        return match ($type) {
            HomeSectionType::Hero => [
                'eyebrow' => self::pair('eyebrow', $input['eyebrow'] ?? null, 40),
                'title' => self::pair('title', $input['title'] ?? null, 80),
                'line' => self::pair('line', $input['line'] ?? null, 160),
                'main' => self::button('main_button', $input['main'] ?? null),
                'second' => self::button('second_button', $input['second'] ?? null),
                'facts' => self::list('facts', $input['facts'] ?? [], self::MAX_FACTS, static fn (mixed $fact): array => self::pair('fact', $fact, 40)),
            ],
            HomeSectionType::Promises => [
                'promises' => self::list('promises', $input['promises'] ?? [], self::MAX_PROMISES, static function (mixed $promise): array {
                    $promise = is_array($promise) ? $promise : [];
                    $icon = $promise['icon'] ?? null;

                    if (! is_string($icon) || ! in_array($icon, self::ICONS, true)) {
                        throw new InvalidContentInput('promise_icon', InvalidContentInput::CHOICE);
                    }

                    return ['icon' => $icon, 'title' => self::pair('promise_title', $promise['title'] ?? null, 40), 'line' => self::pair('promise_line', $promise['line'] ?? null, 120)];
                }),
            ],
            HomeSectionType::Categories, HomeSectionType::BestSellers => [
                'heading' => self::pair('heading', $input['heading'] ?? null, 80),
                'count' => self::count($type, $input['count'] ?? null),
            ],
            HomeSectionType::Projects, HomeSectionType::ClientLogos => [
                'heading' => self::pair('heading', $input['heading'] ?? null, 80),
            ],
            HomeSectionType::Audience => [
                'individuals' => self::audience('individuals', $input['individuals'] ?? null),
                'companies' => self::audience('companies', $input['companies'] ?? null),
            ],
            HomeSectionType::Points => [
                'small_heading' => self::pair('small_heading', $input['small_heading'] ?? null, 40),
                'heading' => self::pair('heading', $input['heading'] ?? null, 80),
                'line' => self::pair('line', $input['line'] ?? null, 160),
            ],
        };
    }

    /**
     * Refuses to show a section missing what it needs: English beside every Arabic, and the type's minimum.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<HomeLogo>  $logos
     *
     * @throws InvalidContentInput|MissingEnglish
     */
    public static function requireShowable(HomeSectionType $type, array $settings, array $logos): void
    {
        self::requireEnglish($settings);

        $required = match ($type) {
            HomeSectionType::Hero => ['title' => $settings['title'] ?? null],
            HomeSectionType::Promises => [],
            HomeSectionType::Audience => ['individuals_title' => $settings['individuals']['title'] ?? null, 'companies_title' => $settings['companies']['title'] ?? null],
            default => ['heading' => $settings['heading'] ?? null],
        };

        foreach ($required as $box => $pair) {
            if (! is_array($pair) || ($pair['ar'] ?? null) === null) {
                throw new InvalidContentInput("{$box}_ar", InvalidContentInput::REQUIRED);
            }
        }

        if ($type === HomeSectionType::Promises && count(is_array($settings['promises'] ?? null) ? $settings['promises'] : []) < self::MIN_PROMISES) {
            throw new InvalidContentInput('promises', InvalidContentInput::TOO_FEW, values: ['min' => self::MIN_PROMISES]);
        }

        if ($type === HomeSectionType::ClientLogos) {
            if (count($logos) < self::MIN_LOGOS) {
                throw new InvalidContentInput('logos', InvalidContentInput::TOO_FEW, values: ['min' => self::MIN_LOGOS]);
            }

            foreach ($logos as $logo) {
                Words::requireEnglish('logo_name', $logo->nameAr, $logo->nameEn);
            }
        }
    }

    /**
     * Refuses an Arabic with no English beside it anywhere in the settings, naming the box.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @throws MissingEnglish
     */
    public static function requireEnglish(array $settings, string $box = ''): void
    {
        if (array_key_exists('ar', $settings) && array_key_exists('en', $settings)) {
            Words::requireEnglish($box === '' ? 'text' : $box, is_string($settings['ar']) ? $settings['ar'] : null, is_string($settings['en']) ? $settings['en'] : null);

            return;
        }

        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                self::requireEnglish($value, is_int($key) ? $box : $key);
            }
        }
    }

    /**
     * The words of one language, as the shop draws them: each pair turned into its text in that language.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array<array-key, mixed>
     */
    public static function in(array $settings, string $locale): array
    {
        $words = [];

        foreach ($settings as $key => $value) {
            $words[$key] = is_array($value) && array_key_exists('ar', $value) && array_key_exists('en', $value) ? $value[$locale] : (is_array($value) ? self::in($value, $locale) : $value);
        }

        return $words;
    }

    /**
     * @return array{ar: string|null, en: string|null}
     *
     * @throws InvalidContentInput
     */
    private static function pair(string $box, mixed $pair, int $max): array
    {
        $pair = is_array($pair) ? $pair : [];

        return Words::pair($box, self::text($box.'_ar', $pair['ar'] ?? null), self::text($box.'_en', $pair['en'] ?? null), $max);
    }

    /**
     * @return array{text: array{ar: string|null, en: string|null}, link: string|null}
     *
     * @throws InvalidContentInput
     */
    private static function button(string $box, mixed $button): array
    {
        $button = is_array($button) ? $button : [];
        $text = self::pair($box, $button['text'] ?? null, 30);
        $link = Words::link("{$box}_link", self::text("{$box}_link", $button['link'] ?? null));

        // Words with nowhere to go, or a link with no words, is half a button.
        if (($text['ar'] === null) !== ($link === null)) {
            throw new InvalidContentInput($text['ar'] === null ? "{$box}_ar" : "{$box}_link", InvalidContentInput::REQUIRED);
        }

        return ['text' => $text, 'link' => $link];
    }

    /**
     * @return array{title: array{ar: string|null, en: string|null}, line: array{ar: string|null, en: string|null}, button: array{text: array{ar: string|null, en: string|null}, link: string|null}}
     *
     * @throws InvalidContentInput
     */
    private static function audience(string $box, mixed $block): array
    {
        $block = is_array($block) ? $block : [];

        return [
            'title' => self::pair("{$box}_title", $block['title'] ?? null, 60),
            'line' => self::pair("{$box}_line", $block['line'] ?? null, 160),
            'button' => self::button("{$box}_button", $block['button'] ?? null),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(mixed): T  $read
     * @return list<T>
     *
     * @throws InvalidContentInput
     */
    private static function list(string $box, mixed $items, int $max, callable $read): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidContentInput($box, InvalidContentInput::FORMAT);
        }

        if (count($items) > $max) {
            throw new InvalidContentInput($box, InvalidContentInput::TOO_MANY, values: ['max' => $max]);
        }

        return array_map($read, $items);
    }

    /**
     * @throws InvalidContentInput
     */
    private static function count(HomeSectionType $type, mixed $count): int
    {
        [$min, $max, $default] = $type === HomeSectionType::BestSellers ? self::COUNTS['BEST_SELLERS'] : self::COUNTS['CATEGORIES'];

        if ($count === null || $count === '') {
            return $default;
        }

        if (! is_int($count) && ! (is_string($count) && preg_match('/\A[0-9]{1,3}\z/', $count) === 1)) {
            throw new InvalidContentInput('count', InvalidContentInput::RANGE, values: ['min' => $min, 'max' => $max]);
        }

        $count = (int) $count;

        if ($count < $min || $count > $max) {
            throw new InvalidContentInput('count', InvalidContentInput::RANGE, values: ['min' => $min, 'max' => $max]);
        }

        return $count;
    }

    /**
     * @throws InvalidContentInput
     */
    private static function text(string $box, mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidContentInput($box, InvalidContentInput::FORMAT);
        }

        return $value;
    }
}
