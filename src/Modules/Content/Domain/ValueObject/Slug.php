<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use Modules\Content\Domain\Exception\InvalidContentInput;
use Normalizer;
use Shared\Domain\Text\LatinDigits;

/**
 * The part of a page's, a post's, a project's or a blog category's address that names it, in one language
 * (content.md §1.1 rule 15): **Arabic letters and digits for `ar`, lower-case Latin letters and digits for `en`**,
 * words joined by single hyphens, never starting or ending with one; at most 200 characters, as
 * `content.slug_history` keeps them.
 *
 * **Made from the title** until staff change it (W7): the Arabic loses its tashkeel, its tatweel and any Latin word
 * in it, the English its accents ("Häfele" gives "hafele"), both their punctuation; Arabic-Indic digits become
 * 0-9; **a long title is cut at 200 characters**, never left for the database to refuse (PR 2 review). Catalog's
 * own `Slug` follows the same rules; Content keeps its copy, Catalog's not being public (handoff §4.5).
 *
 * Whether another item holds it, now or before, is a question about other rows: `SlugTaken`, asked by the writer.
 */
final readonly class Slug
{
    public const int MAX = 200;

    /** Arabic letters: hamza to yeh without tatweel, and the extended letters after them. */
    private const string ARABIC_LETTERS = '\x{0621}-\x{063F}\x{0641}-\x{064A}\x{0671}-\x{06D3}';

    /** Tashkeel, superscript alef and the Quranic marks: dropped from a title before its address is made. */
    private const string ARABIC_MARKS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    private function __construct(
        public string $locale,
        public string $value,
    ) {}

    /**
     * An address typed by staff, held to the rules as it is (made lower-case first, for English).
     *
     * @throws InvalidContentInput
     */
    public static function of(string $attribute, string $locale, string $value): self
    {
        $value = Words::required($attribute, $value, self::MAX);
        $value = $locale === 'en' ? strtolower($value) : $value;

        if (preg_match(self::pattern($locale), $value) !== 1) {
            throw new InvalidContentInput($attribute, $locale === 'ar' ? InvalidContentInput::SLUG_AR : InvalidContentInput::SLUG_EN);
        }

        return new self($locale, $value);
    }

    /**
     * The address a title gives, cut at 200 characters.
     *
     * @throws InvalidContentInput when nothing of the title can stand in an address
     */
    public static function fromTitle(string $attribute, string $locale, string $title): self
    {
        $allowed = $locale === 'ar' ? self::ARABIC_LETTERS.'0-9' : 'a-z0-9';
        $text = mb_strtolower((string) preg_replace(self::ARABIC_MARKS, '', LatinDigits::of($title)), 'UTF-8');

        if ($locale !== 'ar') {
            // "ä" is "a" plus a mark once decomposed; the mark goes, the letter stays.
            $text = (string) preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D));
        }

        $text = trim((string) preg_replace('/[^'.$allowed.']+/u', '-', $text), '-');

        if (mb_strlen($text) > self::MAX) {
            $text = rtrim(mb_substr($text, 0, self::MAX), '-');
        }

        if ($text === '') {
            throw new InvalidContentInput($attribute, InvalidContentInput::SLUG_FROM_TITLE);
        }

        return new self($locale, $text);
    }

    /**
     * The address typed, or else the one the title gives.
     *
     * @throws InvalidContentInput
     */
    public static function typedOrFrom(string $attribute, string $locale, ?string $typed, string $title): self
    {
        return $typed === null || trim($typed) === '' ? self::fromTitle($attribute, $locale, $title) : self::of($attribute, $locale, $typed);
    }

    public static function reconstitute(string $locale, string $value): self
    {
        return new self($locale, $value);
    }

    private static function pattern(string $locale): string
    {
        $word = $locale === 'ar' ? '['.self::ARABIC_LETTERS.'0-9]+' : '[a-z0-9]+';

        return '/\A'.$word.'(-'.$word.')*\z/u';
    }
}
