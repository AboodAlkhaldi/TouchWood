<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * The two ways B2B accepts typed text, so every value object says it the same way.
 *
 * **One line** — a name, a number, a type in the company's own words: trimmed, real UTF-8, no
 * control character at all. **Lines** — an address, a reason, a note (owner, 2026-09-27): the same,
 * except that line breaks are kept, written as "\n" whatever the browser sent.
 *
 * **Trimmed as the page trims** (amendment 17(a)): the company page checks a value before sending
 * it, with JavaScript's `trim()`, so the server removes exactly the same characters at either end —
 * or the page would send a value the server refuses, or hold back one it would take.
 */
final class CompanyText
{
    /**
     * What JavaScript's `trim()` removes, at either end: tab, line feed, vertical tab, form feed,
     * carriage return, every Unicode separator (a space, a no-break space, U+2028, U+2029) and
     * U+FEFF — and nothing else, so a NUL stays and is refused as a control character, on the page
     * and here alike.
     */
    private const string ENDS = '[\t\n\x{0B}\f\r\p{Z}\x{FEFF}]+';

    /**
     * The value without what either end holds of those (amendment 17(a)). Text that is not UTF-8 is
     * given back as it came, for the caller to refuse.
     */
    public static function trimmed(string $value): string
    {
        return preg_replace('/\A'.self::ENDS.'|'.self::ENDS.'\z/u', '', $value) ?? $value;
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function oneLine(string $attribute, string $value, int $max): string
    {
        // Trimmed first: a value pasted from elsewhere often ends in a newline, which is not the
        // typist's mistake. Anything left is a control character in the middle of it.
        return self::checked($attribute, self::trimmed($value), $max, '/\p{Cc}/u', 'on one line, without control characters');
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function lines(string $attribute, string $value, int $max): string
    {
        $text = self::trimmed(str_replace(["\r\n", "\r"], "\n", $value));

        // Any control character but the line break: a tab or a NUL is never what anybody meant.
        return self::checked($attribute, $text, $max, '/[^\P{Cc}\n]/u', 'without control characters');
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    private static function checked(string $attribute, string $text, int $max, string $forbidden, string $refusal): string
    {
        return match (true) {
            $text === '' => throw new InvalidCompanyAttribute($attribute, 'required'),
            preg_match('//u', $text) !== 1 => throw new InvalidCompanyAttribute($attribute, 'text'),
            preg_match($forbidden, $text) === 1 => throw new InvalidCompanyAttribute($attribute, $refusal),
            mb_strlen($text) > $max => throw new InvalidCompanyAttribute($attribute, "at most {$max} characters"),
            default => $text,
        };
    }
}
