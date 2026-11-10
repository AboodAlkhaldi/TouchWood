<?php

declare(strict_types=1);

namespace Modules\Content\Application\Text;

use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\ValueObject\RichText;
use Shared\Domain\Text\LatinDigits;

/**
 * The marks staff type in the editor, and the blocks `RichText` keeps, each into the other (content.md §1.2)
 * - Catalog's `DescriptionText` pattern, extended:
 *
 * | Typed | Kept |
 * |---|---|
 * | a blank line | the end of a paragraph, a list or a table |
 * | `# Title`, `## Title` | a heading, level 2 or 3 |
 * | `- item`, `1. item` | a list, bulleted or numbered (`ordered`) |
 * | `**bold**`, `[words](address)` | a run's `bold`, a run's `link` |
 * | `![description](image:3)`, on a line of its own | an image, `ref` 3 |
 * | `\| a \| b \|` lines, the first the heading | a table |
 *
 * Lines of one paragraph are joined with a space. Nothing else is special, and no HTML survives: it is text
 * like any other. **A mark written wrong is the text it is** - a `**` left open, a `[` with no address - but
 * a link to an address §1.1 rule 16 refuses, an image mark sharing its line, an image with no description
 * and a table row of another width are refused, naming the line ("Line 12: ...").
 *
 * The two ways meet: the typed text a document is written back as reads again as the same document, so a
 * text saved unchanged stays as it was.
 */
final class MarkText
{
    private const string HEADING = '/\A(#{1,2}) (.*)\z/u';

    private const string BULLET = '/\A- (.*)\z/u';

    private const string NUMBERED = '/\A[0-9]{1,4}\. (.*)\z/u';

    private const string IMAGE = '/\A!\[([^\[\]]*)\]\(image:([0-9]{1,6})\)\z/u';

    private const string IMAGE_INSIDE = '/!\[[^\[\]]*\]\(image:[0-9]{1,6}\)/u';

    /** The address runs to the first ")": `[x](javascript:alert(1))` is a link, refused, not text that slips by. */
    private const string LINK = '/\[([^\[\]]+)\]\(([^\s)]*)\)/u';

    /**
     * The typed text as a document. Arabic-Indic digits typed are read as 0-9.
     *
     * @throws InvalidContentInput naming the line where the typed text says it
     */
    public static function blocks(string $attribute, string $typed): RichText
    {
        $reader = new MarkTextReader($attribute);

        foreach (preg_split('/\R/u', LatinDigits::of($typed)) ?: [] as $index => $line) {
            $reader->line(trim($line), $index + 1);
        }

        $reader->close();

        try {
            return RichText::of($attribute, ['blocks' => $reader->blocks]);
        } catch (InvalidContentInput $refused) {
            // The document's own rules name a block: said on the line the block starts on.
            $line = $refused->block === null ? null : ($reader->lines[$refused->block] ?? null);

            throw $line === null ? $refused : $refused->onLine($line);
        }
    }

    /**
     * The document written back as the marks a person types: what the editor's box shows.
     */
    public static function marks(RichText $text): string
    {
        $written = [];

        foreach ($text->toArray()['blocks'] as $block) {
            $written[] = match ($block['type']) {
                'heading' => str_repeat('#', ((int) $block['level']) - 1).' '.self::line($block['runs']),
                'list' => self::listItems($block),
                'image' => '!['.self::string($block['alt']).'](image:'.self::string($block['ref']).')',
                'table' => self::table($block),
                default => self::line($block['runs']),
            };
        }

        return implode("\n\n", $written);
    }

    /**
     * One line of runs as typed: bold between `**`, a link as `[words](address)`.
     */
    public static function line(mixed $runs): string
    {
        $line = '';

        foreach (is_array($runs) ? $runs : [] as $run) {
            if (! is_array($run)) {
                continue;
            }

            $text = self::string($run['text'] ?? '');

            if (is_string($run['link'] ?? null)) {
                $text = '['.$text.']('.$run['link'].')';
            }

            $line .= ($run['bold'] ?? false) === true ? '**'.$text.'**' : $text;
        }

        return $line;
    }

    /**
     * A line's runs from its marks: bold split first, as Catalog's, then each part's links.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidContentInput for a link to an address the rules refuse, or an image mark inside a line
     */
    public static function runs(string $attribute, string $line, int $number): array
    {
        if (preg_match(self::IMAGE_INSIDE, $line) === 1) {
            throw new InvalidContentInput($attribute, InvalidContentInput::IMAGE, $number);
        }

        $parts = explode('**', $line);
        $runs = [];

        foreach ($parts as $index => $part) {
            // An odd part sits between two markers; the last part after an unmatched one is plain.
            $bold = $index % 2 === 1 && $index < count($parts) - 1;

            if (! $bold && $index % 2 === 1) {
                $part = '**'.$part;
            }

            foreach (self::links($attribute, $part, $number) as $run) {
                $runs[] = $bold ? [...$run, 'bold' => true] : $run;
            }
        }

        return $runs;
    }

    /**
     * Whether the typed line is a row of a table: between bars at both ends.
     */
    public static function isTableRow(string $line): bool
    {
        return mb_strlen($line) >= 2 && str_starts_with($line, '|') && str_ends_with($line, '|');
    }

    /**
     * A table row's cells, each trimmed, as typed between the bars.
     *
     * @return list<string>
     */
    public static function cells(string $line): array
    {
        return array_map(trim(...), explode('|', mb_substr($line, 1, mb_strlen($line) - 2)));
    }

    /**
     * @return array{0: string, 1: string}|null the heading's marks and words
     */
    public static function heading(string $line): ?array
    {
        return preg_match(self::HEADING, $line, $found) === 1 ? [$found[1], $found[2]] : null;
    }

    /**
     * @return array{0: bool, 1: string}|null whether the item is numbered, and its words
     */
    public static function item(string $line): ?array
    {
        if (preg_match(self::BULLET, $line, $found) === 1) {
            return [false, $found[1]];
        }

        return preg_match(self::NUMBERED, $line, $found) === 1 ? [true, $found[1]] : null;
    }

    /**
     * @return array{0: string, 1: int}|null the image's description and ref
     */
    public static function image(string $line): ?array
    {
        return preg_match(self::IMAGE, $line, $found) === 1 ? [$found[1], (int) $found[2]] : null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidContentInput
     */
    private static function links(string $attribute, string $part, int $number): array
    {
        $runs = [];
        $at = 0;

        preg_match_all(self::LINK, $part, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($found as $match) {
            [$whole, $start] = $match[0];

            if ($start > $at) {
                $runs[] = ['text' => substr($part, $at, $start - $at)];
            }

            $address = RichText::link($match[2][0]);

            if ($address === null) {
                throw new InvalidContentInput($attribute, InvalidContentInput::LINK, $number);
            }

            $runs[] = ['text' => $match[1][0], 'link' => $address];
            $at = $start + strlen($whole);
        }

        if ($at < strlen($part)) {
            $runs[] = ['text' => substr($part, $at)];
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function listItems(array $block): string
    {
        $items = [];
        $number = 0;

        foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
            $number++;
            $items[] = (($block['ordered'] ?? false) === true ? "{$number}. " : '- ').self::line($item);
        }

        return implode("\n", $items);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function table(array $block): string
    {
        $rows = [is_array($block['head'] ?? null) ? $block['head'] : [], ...(is_array($block['rows'] ?? null) ? $block['rows'] : [])];

        return implode("\n", array_map(
            static fn (mixed $row): string => '| '.implode(' | ', array_map(self::line(...), is_array($row) ? $row : [])).' |',
            $rows,
        ));
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
