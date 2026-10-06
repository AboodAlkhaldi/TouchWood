<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * A description written as plain text in a product file (the guide, §1.4), turned into the
 * structured text the panel keeps (catalog.md §1.1): a blank line starts a paragraph, a line
 * starting `- ` a list item, `# ` a heading, and `**…**` is bold. Lines of one paragraph are joined
 * with a space — the structured text keeps each run on one line. Nothing else is special, and no
 * HTML survives: it is text like any other. StructuredText checks the result as it checks the panel's.
 */
final class DescriptionText
{
    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    public static function document(string $text): array
    {
        /** @var list<array<string, mixed>> $blocks */
        $blocks = [];
        $paragraph = null;
        $items = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                self::close($blocks, $paragraph, $items);
            } elseif (str_starts_with($line, '# ')) {
                self::close($blocks, $paragraph, $items);
                $blocks[] = ['type' => 'heading', 'runs' => self::runs(trim(substr($line, 2)))];
            } elseif (str_starts_with($line, '- ')) {
                if ($paragraph !== null) {
                    self::close($blocks, $paragraph, $items);
                }

                $items ??= [];
                $items[] = trim(substr($line, 2));
            } else {
                if ($items !== null) {
                    self::close($blocks, $paragraph, $items);
                }

                $paragraph = $paragraph === null ? $line : $paragraph.' '.$line;
            }
        }

        self::close($blocks, $paragraph, $items);

        return ['blocks' => $blocks];
    }

    /**
     * Ends the paragraph or the list being written, if any.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>|null  $items
     *
     * @param-out null  $paragraph
     * @param-out null  $items
     */
    private static function close(array &$blocks, ?string &$paragraph, ?array &$items): void
    {
        if ($paragraph !== null) {
            $blocks[] = ['type' => 'paragraph', 'runs' => self::runs($paragraph)];
        }

        if ($items !== null) {
            $blocks[] = ['type' => 'list', 'items' => array_map(self::runs(...), $items)];
        }

        $paragraph = null;
        $items = null;
    }

    /**
     * `**…**` marks bold; a `**` left open is the text it is.
     *
     * @return list<array{text: string, bold?: true}>
     */
    private static function runs(string $line): array
    {
        $parts = explode('**', $line);
        $runs = [];

        foreach ($parts as $index => $part) {
            // An odd part sits between two markers; the last part after an unmatched one is plain.
            $bold = $index % 2 === 1 && $index < count($parts) - 1;

            if (! $bold && $index % 2 === 1) {
                $part = '**'.$part;
            }

            if ($part === '') {
                continue;
            }

            $runs[] = $bold ? ['text' => $part, 'bold' => true] : ['text' => $part];
        }

        return $runs;
    }
}
