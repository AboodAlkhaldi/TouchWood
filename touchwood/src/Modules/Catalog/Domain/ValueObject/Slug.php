<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Service\ArabicText;
use Normalizer;

/**
 * The part of an address that names a product, a category or a brand — **one in Arabic and one in
 * English, used in every store** (catalog.md §1.1, owner 2026-10-02): `ar` takes Arabic letters and
 * digits, `en` lower-case Latin letters and digits (§5.3); both join words with single hyphens and
 * never start or end with one.
 *
 * **Made from the name** when one is not given, and editable (catalog.md §9.3 #3): the English name
 * loses its accents ("Häfele" gives "hafele"), the Arabic its tashkeel, its tatweel and any Latin
 * word in it, both their punctuation; Arabic-Indic digits become 0–9. A slug ever held is never given to another — that is a question about every other
 * row, so the repository answers it (`SlugTaken`), not this class.
 */
final readonly class Slug
{
    public const int MAX = 200;

    /** Arabic letters: hamza to yeh without tatweel, and the extended letters after them. */
    private const string ARABIC_LETTERS = '\x{0621}-\x{063F}\x{0641}-\x{064A}\x{0671}-\x{06D3}';

    private function __construct(
        public string $locale,
        public string $value,
    ) {}

    /**
     * A slug typed by staff, held to the rules as it is.
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $locale, string $value): self
    {
        $attribute = "slug_{$locale}";
        $value = CatalogText::oneLine($attribute, $value, self::MAX);

        if (preg_match(self::pattern($locale), $value) !== 1) {
            throw new InvalidCatalogAttribute($attribute, $locale === 'ar'
                ? 'Arabic letters and digits, joined by single hyphens'
                : 'lower-case Latin letters and digits, joined by single hyphens');
        }

        return new self($locale, $value);
    }

    /**
     * The slug a name gives.
     *
     * @throws InvalidCatalogAttribute when nothing of the name can stand in an address
     */
    public static function fromName(string $locale, string $name): self
    {
        $allowed = $locale === 'ar' ? self::ARABIC_LETTERS.'0-9' : 'a-z0-9';
        // withoutMarks also reads Arabic-Indic digits as 0–9.
        $text = mb_strtolower(ArabicText::withoutMarks($name), 'UTF-8');

        if ($locale !== 'ar') {
            // "ä" is "a" plus a mark once decomposed; the mark goes, the letter stays.
            $text = (string) preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D));
        }

        $text = (string) preg_replace('/[^'.$allowed.']+/u', '-', $text);
        $text = trim($text, '-');

        if (mb_strlen($text) > self::MAX) {
            $text = rtrim(mb_substr($text, 0, self::MAX), '-');
        }

        if ($text === '') {
            throw new InvalidCatalogAttribute("slug_{$locale}", 'a slug: the name has no letters to make one from');
        }

        return new self($locale, $text);
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
