<?php

declare(strict_types=1);

namespace Modules\Content\Application\Text;

use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * Reads typed marks line by line for `MarkText::blocks()`: the blocks so far, and the line each starts on,
 * so a refusal names it. Used by `MarkText` alone.
 *
 * @internal
 */
final class MarkTextReader
{
    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    /** @var list<int> the typed line each block starts on, by the block's place */
    public array $lines = [];

    /** @var array{start: int, text: string}|null */
    private ?array $paragraph = null;

    /** @var array{start: int, ordered: bool, items: list<list<array<string, mixed>>>}|null */
    private ?array $list = null;

    /** @var array{start: int, columns: int, rows: list<list<list<array<string, mixed>>>>}|null */
    private ?array $table = null;

    public function __construct(
        private readonly string $attribute,
    ) {}

    /**
     * @throws InvalidContentInput
     */
    public function line(string $line, int $number): void
    {
        if ($line === '') {
            $this->close();

            return;
        }

        if (MarkText::isTableRow($line)) {
            $this->tableRow($line, $number);

            return;
        }

        $heading = MarkText::heading($line);

        if ($heading !== null) {
            $this->close();
            $this->add(['type' => 'heading', 'level' => strlen($heading[0]) + 1, 'runs' => MarkText::runs($this->attribute, $heading[1], $number)], $number);

            return;
        }

        $image = MarkText::image($line);

        if ($image !== null) {
            // An image with no description is RichText's refusal, said on this line through the block.
            $this->close();
            $this->add(['type' => 'image', 'ref' => $image[1], 'alt' => trim($image[0])], $number);

            return;
        }

        $item = MarkText::item($line);

        if ($item !== null) {
            [$ordered, $words] = $item;

            if ($this->list === null || $this->list['ordered'] !== $ordered) {
                $this->close();
                $this->list = ['start' => $number, 'ordered' => $ordered, 'items' => []];
            }

            $this->list['items'][] = MarkText::runs($this->attribute, $words, $number);

            return;
        }

        // Read on its own first, so a link or an image refused is named on its own line, not the paragraph's first.
        MarkText::runs($this->attribute, $line, $number);

        if ($this->paragraph === null) {
            $this->close();
            $this->paragraph = ['start' => $number, 'text' => $line];
        } else {
            $this->paragraph['text'] .= ' '.$line;
        }
    }

    /**
     * Ends the paragraph, the list or the table being read, if any.
     *
     * @throws InvalidContentInput
     */
    public function close(): void
    {
        if ($this->paragraph !== null) {
            $paragraph = $this->paragraph;
            $this->paragraph = null;
            $this->add(['type' => 'paragraph', 'runs' => MarkText::runs($this->attribute, $paragraph['text'], $paragraph['start'])], $paragraph['start']);
        }

        if ($this->list !== null) {
            $list = $this->list;
            $this->list = null;
            $this->add($list['ordered']
                ? ['type' => 'list', 'ordered' => true, 'items' => $list['items']]
                : ['type' => 'list', 'items' => $list['items']], $list['start']);
        }

        if ($this->table !== null) {
            $table = $this->table;
            $this->table = null;
            [$head, $rows] = [$table['rows'][0], array_slice($table['rows'], 1)];
            $this->add(['type' => 'table', 'head' => $head, 'rows' => $rows], $table['start']);
        }
    }

    /**
     * @throws InvalidContentInput
     */
    private function tableRow(string $line, int $number): void
    {
        $cells = array_map(fn (string $cell): array => $cell === '' ? [] : MarkText::runs($this->attribute, $cell, $number), MarkText::cells($line));

        if ($this->table === null) {
            $this->close();
            $this->table = ['start' => $number, 'columns' => count($cells), 'rows' => [$cells]];

            return;
        }

        // "Line 12: the table has 3 columns here and 4 above." (§1.2)
        if (count($cells) !== $this->table['columns']) {
            throw new InvalidContentInput($this->attribute, InvalidContentInput::TABLE_ROW_COLUMNS, $number, ['found' => count($cells), 'expected' => $this->table['columns']]);
        }

        $this->table['rows'][] = $cells;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function add(array $block, int $line): void
    {
        $this->blocks[] = $block;
        $this->lines[] = $line;
    }
}
