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
     * The shop's language when it was written (§1.1).
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
