<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Service;

use Shared\Domain\Text\LatinDigits;

/**
 * Arabic normalised on write and on query, both sides, or search silently fails (handoff §5.2):
 * tashkeel and tatweel stripped, the alef forms made one, "ى" read as "ي" and "ة" as "ه", and
 * Arabic-Indic digits read as Latin ones. Latin letters are lowered, and every run of spaces becomes
 * one, so "Soft-Close  Hinge" and "soft-close hinge" are the same words.
 *
 * Used for what Catalog compares as words — search words, word pairs (catalog.md §1.11) — and by the
 * slugs, which keep the letters readable but drop the marks (Slug).
 */
final class ArabicText
{
    /** Tashkeel, the superscript alef and the Quranic marks. */
    public const string MARKS = '[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]';

    public const string TATWEEL = '\x{0640}';

    /**
     * The letters only — marks and tatweel gone, digits made Latin — with nothing else touched.
     */
    public static function withoutMarks(string $text): string
    {
        $text = (string) preg_replace('/'.self::MARKS.'|'.self::TATWEEL.'/u', '', $text);

        return LatinDigits::of($text);
    }

    /**
     * The form two words are compared in.
     */
    public static function normalize(string $text): string
    {
        $text = self::withoutMarks($text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
        ]);
        $text = mb_strtolower($text, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
