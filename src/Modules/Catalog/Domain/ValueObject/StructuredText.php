<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * Text with simple formatting — paragraphs, headings, bullet lists and bold — kept as safe
 * structured text (catalog.md §1.1, owner 2026-10-02): **no HTML is ever stored**, so nothing a
 * person types can run as a script, and the screens draw it with their own components.
 *
 * The shape, the same in the database, the import and the screens:
 *
 *     {"blocks": [
 *         {"type": "heading",   "runs": [{"text": "Installation"}]},
 *         {"type": "paragraph", "runs": [{"text": "Fits "}, {"text": "every", "bold": true}, {"text": " cabinet."}]},
 *         {"type": "list",      "items": [[{"text": "Soft close"}], [{"text": "Tool-free"}]]}
 *     ]}
 *
 * Every run is one line of real text; the limit counts the characters of every run together.
 */
final readonly class StructuredText
{
    /** More than any page needs, and a ceiling on what one value may carry. */
    private const int MAX_BLOCKS = 300;

    private const int MAX_ITEMS = 100;

    /**
     * @param  array{blocks: list<array<string, mixed>>}  $document
     */
    private function __construct(
        private array $document,
        private string $plain,
    ) {}

    /**
     * @param  mixed  $document  the decoded value, as it came
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $attribute, mixed $document, int $max): self
    {
        if (! is_array($document) || array_keys($document) !== ['blocks'] || ! is_array($document['blocks']) || ! array_is_list($document['blocks'])) {
            throw new InvalidCatalogAttribute($attribute, 'formatted text: a list of blocks');
        }

        if ($document['blocks'] === []) {
            throw new InvalidCatalogAttribute($attribute, 'required');
        }

        if (count($document['blocks']) > self::MAX_BLOCKS) {
            throw new InvalidCatalogAttribute($attribute, 'at most '.self::MAX_BLOCKS.' blocks');
        }

        $blocks = [];
        $lines = [];

        foreach ($document['blocks'] as $block) {
            [$blocks[], $lines[]] = self::block($attribute, $block);
        }

        $plain = implode("\n", $lines);

        if (mb_strlen(str_replace("\n", '', $plain)) > $max) {
            throw new InvalidCatalogAttribute($attribute, "at most {$max} characters");
        }

        return new self(['blocks' => $blocks], $plain);
    }

    /**
     * A value read back from its column, as `toJson()` wrote it. Checked again on the way in: a row
     * edited past the code must fail loudly here, not draw a broken page.
     *
     * @throws InvalidCatalogAttribute
     */
    public static function fromJson(string $attribute, string $json, int $max): self
    {
        return self::of($attribute, json_decode($json, true, 64, JSON_THROW_ON_ERROR), $max);
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return $this->document;
    }

    /** The words alone, one block a line: for search. */
    public function plain(): string
    {
        return $this->plain;
    }

    /**
     * The exact value as JSON, Arabic left readable: what the column holds, and what the audit log
     * records, so a change of formatting alone is a change.
     */
    public function toJson(): string
    {
        return json_encode($this->document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function equals(self $other): bool
    {
        return $this->document === $other->document;
    }

    /**
     * @return array{array<string, mixed>, string} the block as kept, and its words
     *
     * @throws InvalidCatalogAttribute
     */
    private static function block(string $attribute, mixed $block): array
    {
        if (! is_array($block)) {
            throw new InvalidCatalogAttribute($attribute, 'formatted text: a paragraph, a heading or a list');
        }

        $type = $block['type'] ?? null;

        if ($type === 'paragraph' || $type === 'heading') {
            if (! self::keysAre($block, ['runs', 'type'])) {
                throw new InvalidCatalogAttribute($attribute, "formatted text: a {$type} holds runs only");
            }

            $runs = self::runs($attribute, $block['runs']);

            return [['type' => $type, 'runs' => $runs], self::words($runs)];
        }

        if ($type === 'list') {
            if (! self::keysAre($block, ['items', 'type']) || ! is_array($block['items']) || ! array_is_list($block['items']) || $block['items'] === []) {
                throw new InvalidCatalogAttribute($attribute, 'formatted text: a list holds items');
            }

            if (count($block['items']) > self::MAX_ITEMS) {
                throw new InvalidCatalogAttribute($attribute, 'at most '.self::MAX_ITEMS.' items in a list');
            }

            $items = [];
            $words = [];

            foreach ($block['items'] as $item) {
                $items[] = $runs = self::runs($attribute, $item);
                $words[] = self::words($runs);
            }

            return [['type' => 'list', 'items' => $items], implode("\n", $words)];
        }

        throw new InvalidCatalogAttribute($attribute, 'formatted text: a paragraph, a heading or a list');
    }

    /**
     * @return list<array{text: string, bold?: true}>
     *
     * @throws InvalidCatalogAttribute
     */
    private static function runs(string $attribute, mixed $runs): array
    {
        if (! is_array($runs) || ! array_is_list($runs) || $runs === []) {
            throw new InvalidCatalogAttribute($attribute, 'formatted text: runs of text');
        }

        $kept = [];

        foreach ($runs as $run) {
            if (! is_array($run) || ! is_string($run['text'] ?? null) || array_diff(array_keys($run), ['text', 'bold']) !== []) {
                throw new InvalidCatalogAttribute($attribute, 'formatted text: a run is its text, and whether it is bold');
            }

            $text = $run['text'];

            // A run keeps its own spaces — "Fits " then "every" — so it is not trimmed; it must
            // still be real text, on one line, and not empty.
            if ($text === '' || preg_match('//u', $text) !== 1 || preg_match('/\p{Cc}/u', $text) === 1) {
                throw new InvalidCatalogAttribute($attribute, 'text on one line, without control characters');
            }

            $bold = $run['bold'] ?? false;

            if (! is_bool($bold)) {
                throw new InvalidCatalogAttribute($attribute, 'formatted text: bold is yes or no');
            }

            $kept[] = $bold ? ['text' => $text, 'bold' => true] : ['text' => $text];
        }

        return $kept;
    }

    /**
     * Whether the array has exactly these keys, in any order: PostgreSQL's jsonb sorts an object's
     * keys when it stores them (lesson 38), so a value read back is in another order than written.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $keys  sorted
     */
    private static function keysAre(array $value, array $keys): bool
    {
        $actual = array_map('strval', array_keys($value));
        sort($actual);

        return $actual === $keys;
    }

    /**
     * @param  list<array{text: string, bold?: true}>  $runs
     */
    private static function words(array $runs): string
    {
        return implode('', array_map(static fn (array $run): string => $run['text'], $runs));
    }
}
