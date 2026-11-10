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
 * | a backslash before `\ * [ ] \| ) # - .` or a space | that character as text |
 *
 * Lines of one paragraph are joined with a space. Nothing else is special, and no HTML survives: it is text
 * like any other. **A mark written wrong is the text it is** - a `**` left open, a `[` with no address - but
 * a link to an address §1.1 rule 16 refuses, an image mark sharing its line, an image with no description
 * and a table row of another width are refused, naming the line ("Line 12: ...").
 *
 * The two ways meet: the typed text a document is written back as reads again as the same document -
 * **any** document, its words escaped where they would read as marks (a translated "2024. A good year", an
 * image described "[Photo] cabinet") - so a text saved unchanged stays as it was.
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
     * The characters a backslash before them makes literal (`\*`, `\[`, `\ `...), each kept as a control
     * character while the line is read, so no mark is found in it; RichText refuses control characters in
     * text, so none can stand for anything a person typed.
     *
     * @var array<string, string>
     */
    private const array ESCAPES = [
        '\\' => "\x01", '*' => "\x02", '[' => "\x03", ']' => "\x04", '|' => "\x05",
        ')' => "\x06", '#' => "\x07", '-' => "\x08", '.' => "\x0F", ' ' => "\x0E",
    ];

    /**
     * The typed text as a document. Arabic-Indic digits typed are read as 0-9.
     *
     * @throws InvalidContentInput naming the line where the typed text says it
     */
    public static function blocks(string $attribute, string $typed): RichText
    {
        $reader = new MarkTextReader($attribute);

        foreach (preg_split('/\R/u', LatinDigits::of($typed)) ?: [] as $index => $line) {
            // A control character that would read as an escape is refused on its line, as RichText would.
            if (strpbrk($line, implode('', self::ESCAPES)) !== false) {
                throw new InvalidContentInput($attribute, InvalidContentInput::ONE_LINE, $index + 1);
            }

            $reader->line(trim(self::mask($line)), $index + 1);
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
     * The document written back as the marks a person types: what the editor's box shows. Text that would
     * read as a mark is written with a backslash before it, by its place - a `#`, a `-` or a `2024.` that
     * opens a paragraph, every `[`, `]` and `|`, a `*` beside another or at a run's edge, a space at a
     * line's edge - so the marks read back as this same document, whatever its words.
     */
    public static function marks(RichText $text): string
    {
        $written = [];

        foreach ($text->toArray()['blocks'] as $block) {
            $written[] = match ($block['type']) {
                'heading' => str_repeat('#', ((int) $block['level']) - 1).' '.self::line($block['runs']),
                'list' => self::listItems($block),
                'image' => '!['.self::escape(self::string($block['alt']), false, false).'](image:'.self::string($block['ref']).')',
                'table' => self::table($block),
                default => self::paragraphStart(self::line($block['runs'])),
            };
        }

        return implode("\n\n", $written);
    }

    /**
     * One line of runs as typed: bold between `**`, a link as `[words](address)`, every literal mark escaped.
     */
    public static function line(mixed $runs): string
    {
        $runs = array_values(array_filter(is_array($runs) ? $runs : [], is_array(...)));
        $last = count($runs) - 1;
        $line = '';

        foreach ($runs as $index => $run) {
            $plain = ! is_string($run['link'] ?? null) && ($run['bold'] ?? false) !== true;
            // A space at the line's own edge would be trimmed away: only a plain run reaches the edge.
            $text = self::escape(self::string($run['text'] ?? ''), $plain && $index === 0, $plain && $index === $last);

            if (is_string($run['link'] ?? null)) {
                $text = '['.$text.']('.strtr($run['link'], [')' => '\\)', '*' => '\\*', '|' => '\\|']).')';
            }

            $line .= ($run['bold'] ?? false) === true ? '**'.$text.'**' : $text;
        }

        return $line;
    }

    /**
     * A line's runs from its marks, the line as `mask()` gives it: bold split first, as Catalog's, then each
     * part's links; the escaped characters given back as the characters they are.
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

    /** The escaped characters of a masked text given back as the characters they are. */
    public static function unmask(string $text): string
    {
        return strtr($text, array_flip(self::ESCAPES));
    }

    /**
     * A line with each backslash-escaped character turned into its stand-in, so nothing reads it as a mark.
     * A backslash before any other character is the backslash it is.
     */
    private static function mask(string $line): string
    {
        $characters = mb_str_split($line);
        $masked = '';

        for ($at = 0, $count = count($characters); $at < $count; $at++) {
            $next = $characters[$at + 1] ?? null;

            if ($characters[$at] === '\\' && $next !== null && isset(self::ESCAPES[$next])) {
                $masked .= self::ESCAPES[$next];
                $at++;

                continue;
            }

            $masked .= $characters[$at];
        }

        return $masked;
    }

    /**
     * A run's words written so none of them reads as a mark: `[`, `]` and `|` always escaped, a `*` beside
     * another or at the run's edge, a backslash before an escapable character or at the end, and a space at
     * the line's edge when the run stands there.
     */
    private static function escape(string $text, bool $atLineStart, bool $atLineEnd): string
    {
        $characters = mb_str_split($text);
        $last = count($characters) - 1;
        $written = '';

        foreach ($characters as $at => $character) {
            $previous = $characters[$at - 1] ?? null;
            $next = $characters[$at + 1] ?? null;

            $escaped = match ($character) {
                '[', ']', '|' => true,
                '*' => $previous === null || $next === null || $previous === '*' || $next === '*',
                '\\' => $next === null || isset(self::ESCAPES[$next]),
                ' ' => ($at === 0 && $atLineStart) || ($at === $last && $atLineEnd),
                default => false,
            };

            $written .= ($escaped ? '\\' : '').$character;
        }

        return $written;
    }

    /** A paragraph's line that would open as a heading, a list item or a numbered item, escaped there. */
    private static function paragraphStart(string $line): string
    {
        if (str_starts_with($line, '#') || str_starts_with($line, '-')) {
            return '\\'.$line;
        }

        return (string) preg_replace('/\A([0-9]+)\./', '$1\\\\.', $line, 1);
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
                $runs[] = ['text' => self::unmask(substr($part, $at, $start - $at))];
            }

            $address = RichText::link(self::unmask($match[2][0]));

            if ($address === null) {
                throw new InvalidContentInput($attribute, InvalidContentInput::LINK, $number);
            }

            $runs[] = ['text' => self::unmask($match[1][0]), 'link' => $address];
            $at = $start + strlen($whole);
        }

        if ($at < strlen($part)) {
            $runs[] = ['text' => self::unmask(substr($part, $at))];
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
