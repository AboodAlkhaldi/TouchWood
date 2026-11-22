<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Service\ArabicText;

/**
 * A product's extra search words (catalog.md §1.1, §9.3 #5), in either language: at most 30, each one
 * line of at most 50 characters — counted as typed and as search keeps it. Each is kept **as typed,
 * and as search reads it** (handoff §5.2); a word typed twice, or two spellings search reads as one,
 * is kept once, quietly — the first one typed (owner, 2026-10-03, amendment 3(f)).
 */
final readonly class SearchWords
{
    public const int MAX = 30;

    public const int WORD_MAX = 50;

    /**
     * @param  list<array{word: string, normalized: string}>  $words
     */
    private function __construct(
        public array $words,
    ) {}

    /**
     * @param  array<array-key, mixed>  $words  as the request sent them
     *
     * @throws InvalidCatalogAttribute|TooMany
     */
    public static function of(array $words): self
    {
        // Duplicates are kept once, so a few more may be sent than kept — not without end.
        if (count($words) > self::MAX * 10) {
            throw new TooMany('search_words', self::MAX);
        }

        $kept = [];

        foreach ($words as $word) {
            if (! is_string($word)) {
                throw new InvalidCatalogAttribute('search_words', 'words');
            }

            $word = CatalogText::oneLine('search_words', $word, self::WORD_MAX);
            $normalized = ArabicText::normalize($word);

            if ($normalized === '' || mb_strlen($normalized) > self::WORD_MAX) {
                throw new InvalidCatalogAttribute('search_words', 'words of at most '.self::WORD_MAX.' characters');
            }

            $kept[$normalized] ??= ['word' => $word, 'normalized' => $normalized];
        }

        if (count($kept) > self::MAX) {
            throw new TooMany('search_words', self::MAX);
        }

        return new self(array_values($kept));
    }

    /**
     * @param  list<array{word: string, normalized: string}>  $words
     */
    public static function reconstitute(array $words): self
    {
        return new self($words);
    }

    /** The words as typed, in order — one value, for the audit. */
    public function asText(): ?string
    {
        return $this->words === [] ? null : json_encode(array_map(static fn (array $word): string => $word['word'], $this->words), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
