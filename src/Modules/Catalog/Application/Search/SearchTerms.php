<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Search;

use Modules\Catalog\Domain\Service\ArabicText;

/**
 * What a shopper typed, as search reads it (catalog.md §1.11): normalised as every word in the
 * listing was (handoff §5.2), cut into words — letters and digits only, so nothing typed can reach
 * the query's own syntax — and each word, or run of words, widened by the shared word pairs that
 * name it ("مفصلة" ↔ "hinge"). Each word matches the start of a word (a prefix), so a shopper still
 * typing is already answered.
 *
 * The three questions it asks of a row's search document (§5.4): every word in a **name** (A); every
 * word, or its pair, in a name or a **search word** (A, B); and the same in the **names of its
 * categories** too (C).
 */
final readonly class SearchTerms
{
    /** As long as the search log keeps a query (§5.4). */
    public const int TEXT_MAX = 200;

    /** Words beyond these add nothing a shopper meant. */
    public const int WORDS_MAX = 10;

    /**
     * @param  list<string>  $words
     * @param  list<array{words: list<string>, alternatives: list<list<string>>}>  $groups  in the order typed
     */
    private function __construct(
        public string $text,
        public array $words,
        private array $groups,
    ) {}

    public static function of(string $typed): self
    {
        $text = mb_substr(ArabicText::normalize($typed), 0, self::TEXT_MAX);
        $words = array_slice(self::words($text), 0, self::WORDS_MAX);

        return new self($text, $words, array_map(static fn (string $word): array => ['words' => [$word], 'alternatives' => []], $words));
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }

    /**
     * The same words, each word or run of words a pair names widened by its partner. A run of
     * several words is matched before a single one, so "soft close" ↔ "ناعم" widens the two words
     * together.
     *
     * @param  list<array{string, string}>  $pairs  as stored: normalised
     */
    public function withPairs(array $pairs): self
    {
        $sides = [];

        foreach ($pairs as [$a, $b]) {
            $sides[] = [self::words($a), self::words($b)];
            $sides[] = [self::words($b), self::words($a)];
        }

        usort($sides, static fn (array $one, array $other): int => count($other[0]) <=> count($one[0]));
        $count = count($this->words);
        /** @var array<int, int> $cover word index => group */
        $cover = [];
        /** @var list<array{start: int, end: int, alternatives: list<list<string>>}> $runs */
        $runs = [];

        foreach ($sides as [$side, $partner]) {
            $length = count($side);

            if ($length === 0 || $partner === []) {
                continue;
            }

            for ($start = 0; $start + $length <= $count; $start++) {
                if (array_slice($this->words, $start, $length) !== $side) {
                    continue;
                }

                $group = $cover[$start] ?? null;

                if ($group !== null && $runs[$group]['start'] === $start && $runs[$group]['end'] === $start + $length - 1) {
                    $runs[$group]['alternatives'][] = $partner;

                    break;
                }

                if (array_intersect_key($cover, array_flip(range($start, $start + $length - 1))) !== []) {
                    continue;
                }

                $runs[] = ['start' => $start, 'end' => $start + $length - 1, 'alternatives' => [$partner]];

                for ($index = $start; $index < $start + $length; $index++) {
                    $cover[$index] = count($runs) - 1;
                }

                break;
            }
        }

        $groups = [];

        for ($index = 0; $index < $count; $index++) {
            $group = $cover[$index] ?? null;

            if ($group === null) {
                $groups[] = ['words' => [$this->words[$index]], 'alternatives' => []];
            } elseif ($runs[$group]['start'] === $index) {
                $groups[] = ['words' => array_slice($this->words, $index, $runs[$group]['end'] - $index + 1), 'alternatives' => array_values(array_unique($runs[$group]['alternatives'], SORT_REGULAR))];
            }
        }

        return new self($this->text, $this->words, $groups);
    }

    /** Every word typed starts a word of a name. */
    public function inNames(): string
    {
        return implode(' & ', array_map(static fn (string $word): string => "{$word}:*A", $this->words));
    }

    /** Every word, or its pair, starts a word of a name or a search word. */
    public function inWords(): string
    {
        return $this->query('AB');
    }

    /** As inWords(), the names of its categories counted too. */
    public function anywhere(): string
    {
        return $this->query('ABC');
    }

    private function query(string $weights): string
    {
        $phrase = static fn (array $words): string => implode(' <-> ', array_map(static fn (string $word): string => "{$word}:*{$weights}", $words));

        return implode(' & ', array_map(
            static fn (array $group): string => '('.implode(' | ', array_map($phrase, [$group['words'], ...$group['alternatives']])).')',
            $this->groups,
        ));
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], static fn (string $word): bool => $word !== ''));
    }
}
