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
 * **Marks go with the words** (§1.3 (a), built 2026-11-10 after the owner's test on the real account): each
 * piece also as `TranslationTags` writes it - bold as `<b>`, a link as `<a i="0">` with its address kept
 * here - and is rebuilt from the answer with its marks (`rebuildMarked()`). When an answer does not hold the
 * tags sent, the caller drops the box's marks (`rebuild()` over `TranslationTags::plain()`), and counts the
 * pieces that held them, so the editor can say how many lost them.
 */
final class TranslationPieces
{
    /**
     * The pieces to send, in order: each one's words, whether it held bold or a link, and its words as the
     * XML sent with tag handling. A piece of no words (an empty table cell) is not one: it is left as it is.
     *
     * @return array{pieces: list<string>, marked: list<bool>, tagged: list<string>}
     */
    public static function of(RichText $text): array
    {
        $pieces = [];
        $marked = [];
        $tagged = [];

        self::walk($text->toArray(), function (array $runs) use (&$pieces, &$marked, &$tagged): null {
            $pieces[] = self::words($runs);
            $marked[] = array_filter($runs, static fn (array $run): bool => isset($run['bold']) || isset($run['link'])) !== [];
            $tagged[] = TranslationTags::encode($runs)['xml'];

            return null;
        });

        return ['pieces' => $pieces, 'marked' => $marked, 'tagged' => $tagged];
    }

    /**
     * The same document with each piece's words replaced by its translation as plain words, in the order
     * `of()` gave them, read again as typed text is: the same limits, the same checks.
     *
     * @param  list<string>  $translated  as many as `of()` gave pieces
     *
     * @throws InvalidContentInput when the translation breaks a rule of the document
     */
    public static function rebuild(string $attribute, RichText $text, array $translated): RichText
    {
        $next = 0;

        $document = self::walk($text->toArray(), function (array $runs) use ($translated, &$next): array {
            return [['text' => self::oneLine($translated[$next++] ?? self::words($runs))]];
        });

        return RichText::of($attribute, $document);
    }

    /**
     * The same document from answers to the tagged pieces, each piece's runs read back with their bold and
     * links (`TranslationTags::parse()`); null when one answer is not the tags sent, or the document it gives
     * breaks a rule - the caller then drops the marks of this box, and only this box.
     *
     * @param  list<string>  $translated  as many as `of()` gave pieces, each an answer to its `tagged`
     */
    public static function rebuildMarked(string $attribute, RichText $text, array $translated): ?RichText
    {
        $next = 0;
        $refused = false;

        $document = self::walk($text->toArray(), function (array $runs) use ($translated, &$next, &$refused): ?array {
            $sent = TranslationTags::encode($runs);
            $answer = $translated[$next++] ?? null;
            $read = $answer === null ? null : TranslationTags::parse($answer, $sent['links'], $sent['bolds']);
            $refused = $refused || $read === null;

            return $read;
        });

        if ($refused) {
            return null;
        }

        try {
            return RichText::of($attribute, $document);
        } catch (InvalidContentInput) {
            return null;
        }
    }

    /**
     * Visits every piece in the document's order with its runs (an image's description as one plain run);
     * what the visitor answers, when runs, replaces the piece's.
     *
     * @param  array{blocks: list<array<string, mixed>>}  $document
     * @param  Closure(list<array<string, mixed>>): ?list<array<string, mixed>>  $visit
     * @return array{blocks: list<array<string, mixed>>}
     */
    private static function walk(array $document, Closure $visit): array
    {
        $runs = static function (mixed $runs) use ($visit): mixed {
            $kept = array_values(array_filter(is_array($runs) ? $runs : [], is_array(...)));

            if (trim(self::words($kept)) === '') {
                return $runs;
            }

            return $visit($kept) ?? $runs;
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
                    $replaced = trim($alt) === '' ? null : $visit([['text' => $alt]]);
                    // A description is words alone: whatever marks an answer held, its words are kept.
                    $block['alt'] = $replaced === null ? $alt : self::words($replaced);
                    break;
            }

            $blocks[] = $block;
        }

        return ['blocks' => $blocks];
    }

    /**
     * A line's words, its runs' texts joined.
     *
     * @param  list<array<string, mixed>>  $runs
     */
    private static function words(array $runs): string
    {
        return implode('', array_map(static fn (array $run): string => is_string($run['text'] ?? null) ? $run['text'] : '', $runs));
    }

    /** A translation kept to one line, as every piece is: a line break the service added is a space. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text));
    }
}
