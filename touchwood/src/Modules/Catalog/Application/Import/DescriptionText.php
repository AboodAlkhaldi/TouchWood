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
     * The other way: the structured text written back as the same plain text, for a form to edit
     * (catalog.md §4.4, P1). Reading the answer again gives the same document — a paragraph on one
     * line, a list item per line, a heading, bold — so a description saved unchanged stays as it was.
     *
     * @param  array<string, mixed>|null  $document
     */
    public static function text(?array $document): string
    {
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $written = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $written[] = match ($block['type'] ?? null) {
                'heading' => '# '.self::line($block['runs'] ?? []),
                'list' => implode("\n", array_map(
                    static fn (mixed $item): string => '- '.self::line($item),
                    is_array($block['items'] ?? null) ? $block['items'] : [],
                )),
                default => self::line($block['runs'] ?? []),
            };
        }

        return implode("\n\n", $written);
    }

    /**
     * One line of runs, bold ones between `**`.
     */
    private static function line(mixed $runs): string
    {
        $line = '';

        foreach (is_array($runs) ? $runs : [] as $run) {
            if (! is_array($run) || ! is_string($run['text'] ?? null)) {
                continue;
            }

            $line .= ($run['bold'] ?? false) === true ? '**'.$run['text'].'**' : $run['text'];
        }

        return $line;
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
