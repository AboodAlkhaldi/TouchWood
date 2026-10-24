<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Model;

use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Domain\Text\LatinDigits;

/**
 * What customers and staff type (feedback.md §1.1, §1.3): trimmed, line breaks kept, no formatting,
 * Arabic digits saved as 0-9 (frontend.md §1.8); its length in characters, not bytes, within the
 * spec's bounds — as the database's CHECKs hold them.
 */
final class FeedbackText
{
    /**
     * @throws InvalidFeedbackAttribute
     */
    public static function of(string $typed, string $attribute, int $max): string
    {
        $text = LatinDigits::of(trim(str_replace(["\r\n", "\r"], "\n", $typed)));
        $length = mb_strlen($text);

        if ($length < 1 || $length > $max) {
            throw new InvalidFeedbackAttribute($attribute, "1 to {$max} characters");
        }

        return $text;
    }

    /**
     * A moderator's reason: none, or 1 to 500 characters (§1.1) — spaces alone are none.
     *
     * @throws InvalidFeedbackAttribute
     */
    public static function reason(?string $typed): ?string
    {
        return $typed === null || trim($typed) === '' ? null : self::of($typed, 'reason', 500);
    }

    /**
     * @throws InvalidFeedbackAttribute
     */
    public static function rating(int $rating): int
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidFeedbackAttribute('rating', '1 to 5');
        }

        return $rating;
    }

    /**
     * **The language a text is written in** (§2.4 B3; owner, 2026-10-24): its first letter decides — an
     * Arabic letter, Arabic; any other letter, English; digits, spaces, signs and emoji are skipped, and a
     * text with no letter is English. The owner may change the method later; staff may set it (`locale`).
     *
     * @return 'ar'|'en'
     */
    public static function languageOf(string $text): string
    {
        // The first letter of any script; the tatweel (U+0640, a letter of no script of its own) is Arabic,
        // whatever the PCRE build's script tables say.
        if (preg_match('/\p{L}/u', $text, $first) !== 1) {
            return 'en';
        }

        return $first[0] === "\u{0640}" || preg_match('/\p{Arabic}/u', $first[0]) === 1 ? 'ar' : 'en';
    }

    /**
     * A language staff set (§2.4 B3): Arabic or English.
     *
     * @throws InvalidFeedbackAttribute
     */
    public static function locale(string $locale): string
    {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidFeedbackAttribute('locale', 'ar or en');
        }

        return $locale;
    }
}
