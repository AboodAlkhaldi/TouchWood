<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * How Catalog accepts typed text, so every value object says it the same way: **one line** — a
 * name, a word, a slug's source — trimmed, real UTF-8, no control character or line separator.
 *
 * **Trimmed as a page trims** (the rule B2B's company page settled, b2b.md amendment 17(a)): exactly
 * what JavaScript's `trim()` removes at either end — tab, line breaks, vertical tab, form feed, every
 * Unicode space separator and U+FEFF — so a page that checks a value before sending it never
 * disagrees with the server about it. A NUL stays, and is refused as a control character.
 */
final class CatalogText
{
    /**
     * What breaks a line: every control character, and the Unicode line and paragraph separators
     * (U+2028, U+2029), which are not control characters but start a new line all the same.
     */
    public const string LINE_BREAK = '/[\p{Cc}\p{Zl}\p{Zp}]/u';

    private const string ENDS = '[\t\n\x{0B}\f\r\p{Z}\x{FEFF}]+';

    /**
     * The value without those characters at either end. Text that is not UTF-8 is given back as it
     * came, for the caller to refuse.
     */
    public static function trimmed(string $value): string
    {
        return preg_replace('/\A'.self::ENDS.'|'.self::ENDS.'\z/u', '', $value) ?? $value;
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function oneLine(string $attribute, string $value, int $max): string
    {
        $text = self::trimmed($value);

        return match (true) {
            $text === '' => throw new InvalidCatalogAttribute($attribute, 'required'),
            preg_match('//u', $text) !== 1 => throw new InvalidCatalogAttribute($attribute, 'text'),
            preg_match(self::LINE_BREAK, $text) === 1 => throw new InvalidCatalogAttribute($attribute, 'on one line, without control characters'),
            mb_strlen($text) > $max => throw new InvalidCatalogAttribute($attribute, "at most {$max} characters"),
            default => $text,
        };
    }

    /**
     * One line, or nothing: an empty value is null, anything else is held to oneLine().
     *
     * @throws InvalidCatalogAttribute
     */
    public static function optionalLine(string $attribute, ?string $value, int $max): ?string
    {
        if ($value === null || self::trimmed($value) === '') {
            return null;
        }

        return self::oneLine($attribute, $value, $max);
    }
}
