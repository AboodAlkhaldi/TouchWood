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
 */
final class CompanyText
{
    /**
     * @throws InvalidCompanyAttribute
     */
    public static function oneLine(string $attribute, string $value, int $max): string
    {
        // Trimmed first: a value pasted from elsewhere often ends in a newline, which is not the
        // typist's mistake. Anything left is a control character in the middle of it.
        return self::checked($attribute, trim($value), $max, '/\p{Cc}/u', 'on one line, without control characters');
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function lines(string $attribute, string $value, int $max): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $value));

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
