<?php

declare(strict_types=1);

namespace Modules\Content\Application\Text;

use Closure;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\ValueObject\RichText;

/**
 * A formatted text cut into the pieces Translate sends, and put back together from their translations
 * (content.md §1.3): **block by block** - each heading, paragraph, list item, table cell and image
 * description its own text - so the document's structure comes back whatever the service does. Images keep
 * their place, and no address ever goes to the service.
 *
 * Marks are sent as plain text (§1.3 (b)): whether DeepL keeps inline marks in Arabic waits for the real
 * account (PARKED, plan §S P1). A piece that held bold or a link comes back plain, and is counted, so the
 * editor can say how many lost them.
 */
final class TranslationPieces
{
    /**
     * The pieces to send, in order, and for each whether it held bold or a link. A piece of no words (an
     * empty table cell) is not one: it is left as it is.
     *
     * @return array{pieces: list<string>, marked: list<bool>}
     */
    public static function of(RichText $text): array
    {
        $pieces = [];
        $marked = [];

        self::walk($text->toArray(), function (string $words, bool $hasMarks) use (&$pieces, &$marked): null {
            $pieces[] = $words;
            $marked[] = $hasMarks;

            return null;
        });

        return ['pieces' => $pieces, 'marked' => $marked];
    }

    /**
     * The same document with each piece's words replaced by its translation, in the order `of()` gave them,
     * read again as typed text is: the same limits, the same checks.
     *
     * @param  list<string>  $translated  as many as `of()` gave pieces
     *
     * @throws InvalidContentInput when the translation breaks a rule of the document
     */
    public static function rebuild(string $attribute, RichText $text, array $translated): RichText
    {
        $next = 0;

        $document = self::walk($text->toArray(), function (string $words, bool $hasMarks) use ($translated, &$next): string {
            return self::oneLine($translated[$next++] ?? $words);
        });

        return RichText::of($attribute, $document);
    }

    /**
     * Visits every piece in the document's order; what the visitor answers, when a string, replaces the
     * piece's words as one plain run.
     *
     * @param  array{blocks: list<array<string, mixed>>}  $document
     * @param  Closure(string, bool): ?string  $visit
     * @return array{blocks: list<array<string, mixed>>}
     */
    private static function walk(array $document, Closure $visit): array
    {
        $runs = static function (mixed $runs) use ($visit): mixed {
            $words = '';
            $hasMarks = false;

            foreach (is_array($runs) ? $runs : [] as $run) {
                if (is_array($run)) {
                    $words .= is_string($run['text'] ?? null) ? $run['text'] : '';
                    $hasMarks = $hasMarks || isset($run['bold']) || isset($run['link']);
                }
            }

            if (trim($words) === '') {
                return $runs;
            }

            $replaced = $visit($words, $hasMarks);

            return $replaced === null ? $runs : [['text' => $replaced]];
        };

        $blocks = [];

        foreach ($document['blocks'] as $block) {
            switch ($block['type'] ?? null) {
                case 'paragraph':
                case 'heading':
                    $block['runs'] = $runs($block['runs'] ?? []);
                    break;
                case 'list':
                    $block['items'] = array_map($runs, is_array($block['items'] ?? null) ? $block['items'] : []);
                    break;
                case 'table':
                    $block['head'] = array_map($runs, is_array($block['head'] ?? null) ? $block['head'] : []);
                    $block['rows'] = array_map(
                        static fn (mixed $row): array => array_map($runs, is_array($row) ? $row : []),
                        is_array($block['rows'] ?? null) ? $block['rows'] : [],
                    );
                    break;
                case 'image':
                    $alt = is_string($block['alt'] ?? null) ? $block['alt'] : '';
                    $replaced = trim($alt) === '' ? null : $visit($alt, false);
                    $block['alt'] = $replaced ?? $alt;
                    break;
            }

            $blocks[] = $block;
        }

        return ['blocks' => $blocks];
    }

    /** A translation kept to one line, as every piece is: a line break the service added is a space. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text));
    }
}
