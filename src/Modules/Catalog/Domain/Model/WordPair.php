<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Catalog\Domain\ValueObject\CatalogText;

/**
 * Two words that mean the same to a shopper, for every product at once — "مفصلة" and "hinge"
 * (catalog.md §1.11, owner 2026-10-02): one entry fixes a zero-result search for the whole shop.
 *
 * Kept **as search compares words** (handoff §5.2: Arabic normalised, letters lowered), and in
 * order, so "hinge ↔ مفصلة" and "مفصلة ↔ hinge" are one pair; a word is never paired with itself.
 * A pair is added or deleted, never edited.
 */
final readonly class WordPair
{
    public const int WORD_MAX = 50;

    private function __construct(
        public string $id,
        public string $wordA,
        public string $wordB,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function add(string $id, string $one, string $other): self
    {
        $one = self::word('word_a', $one);
        $other = self::word('word_b', $other);

        if ($one === $other) {
            throw new InvalidCatalogAttribute('word_b', 'a word other than the first');
        }

        return strcmp($one, $other) < 0 ? new self($id, $one, $other) : new self($id, $other, $one);
    }

    public static function reconstitute(string $id, string $wordA, string $wordB): self
    {
        return new self($id, $wordA, $wordB);
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function word(string $attribute, string $word): string
    {
        $normalized = ArabicText::normalize(CatalogText::oneLine($attribute, $word, self::WORD_MAX));

        if ($normalized === '') {
            throw new InvalidCatalogAttribute($attribute, 'required');
        }

        // Counted again as it is kept: lower-casing can lengthen a word ("İ" becomes two characters).
        if (mb_strlen($normalized) > self::WORD_MAX) {
            throw new InvalidCatalogAttribute($attribute, 'at most '.self::WORD_MAX.' characters');
        }

        return $normalized;
    }
}
