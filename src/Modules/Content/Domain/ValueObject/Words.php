<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Shared\Domain\Text\LatinDigits;

/**
 * The short words staff type in Content's boxes (content.md §1.1 rules 2 and 3): one line each, trimmed, at most
 * their box's characters, every digit saved 0-9; an empty box is null.
 *
 * **A pair** is a box's Arabic and English. Arabic is required to save at all - so English typed beside an empty
 * Arabic is refused on the Arabic - and English to publish, schedule or show (`requireEnglish`), named by its box
 * (`MissingEnglish`).
 */
final class Words
{
    /** A line break, a control character, or a line or paragraph separator: as `RichText::line`. */
    private const string NOT_ONE_LINE = '/[\p{Cc}\p{Zl}\p{Zp}]/u';

    /**
     * One line of at most `$max` characters, or null when nothing is typed.
     *
     * @throws InvalidContentInput
     */
    public static function line(string $attribute, ?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidContentInput($attribute, InvalidContentInput::FORMAT);
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match(self::NOT_ONE_LINE, $value) === 1) {
            throw new InvalidContentInput($attribute, InvalidContentInput::ONE_LINE);
        }

        $value = LatinDigits::of($value);

        if (mb_strlen($value) > $max) {
            throw new InvalidContentInput($attribute, InvalidContentInput::TOO_LONG, values: ['max' => $max]);
        }

        return $value;
    }

    /**
     * One line that must be there.
     *
     * @throws InvalidContentInput
     */
    public static function required(string $attribute, ?string $value, int $max): string
    {
        return self::line($attribute, $value, $max) ?? throw new InvalidContentInput($attribute, InvalidContentInput::REQUIRED);
    }

    /**
     * A box's two languages, `{attribute}_ar` and `{attribute}_en`: English with no Arabic beside it is refused on
     * the Arabic (Arabic is required to save at all).
     *
     * @return array{ar: string|null, en: string|null}
     *
     * @throws InvalidContentInput
     */
    public static function pair(string $attribute, ?string $ar, ?string $en, int $max): array
    {
        $pair = ['ar' => self::line("{$attribute}_ar", $ar, $max), 'en' => self::line("{$attribute}_en", $en, $max)];

        if ($pair['ar'] === null && $pair['en'] !== null) {
            throw new InvalidContentInput("{$attribute}_ar", InvalidContentInput::REQUIRED);
        }

        return $pair;
    }

    /**
     * A pair whose Arabic must be there.
     *
     * @return array{ar: string, en: string|null}
     *
     * @throws InvalidContentInput
     */
    public static function arabic(string $attribute, ?string $ar, ?string $en, int $max): array
    {
        $pair = self::pair($attribute, $ar, $en, $max);

        return ['ar' => $pair['ar'] ?? throw new InvalidContentInput("{$attribute}_ar", InvalidContentInput::REQUIRED), 'en' => $pair['en']];
    }

    /**
     * A pair both of whose languages must be there, the box having no Publish step (§1.1 rule 2).
     *
     * @return array{ar: string, en: string}
     *
     * @throws InvalidContentInput|MissingEnglish
     */
    public static function both(string $attribute, ?string $ar, ?string $en, int $max): array
    {
        $pair = self::arabic($attribute, $ar, $en, $max);

        return ['ar' => $pair['ar'], 'en' => $pair['en'] ?? throw new MissingEnglish($attribute)];
    }

    /**
     * Either both languages or neither.
     *
     * @return array{ar: string|null, en: string|null}
     *
     * @throws InvalidContentInput|MissingEnglish
     */
    public static function bothOrNone(string $attribute, ?string $ar, ?string $en, int $max): array
    {
        $pair = self::pair($attribute, $ar, $en, $max);

        if ($pair['ar'] !== null && $pair['en'] === null) {
            throw new MissingEnglish($attribute);
        }

        return $pair;
    }

    /**
     * Refuses an Arabic side with no English beside it: Publish, Schedule or Show (§1.1 rule 2).
     *
     * @throws MissingEnglish
     */
    public static function requireEnglish(string $attribute, ?string $ar, ?string $en): void
    {
        if ($ar !== null && $en === null) {
            throw new MissingEnglish($attribute);
        }
    }

    /**
     * A link as §1.1 rule 16 takes it - a page of the shop, or a full https:// address - or null for none.
     *
     * @throws InvalidContentInput
     */
    public static function link(string $attribute, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return RichText::link($value) ?? throw new InvalidContentInput($attribute, InvalidContentInput::LINK);
    }
}
