<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * Reads one document's blocks for `RichText`, counting its characters and images as it goes, so a value
 * past a limit is refused as soon as it passes it. Used by `RichText::of()` alone.
 *
 * @internal
 */
final class RichTextReader
{
    public int $characters = 0;

    /** @var list<int> */
    public array $images = [];

    public function __construct(
        private readonly string $attribute,
    ) {}

    /**
     * @return array<string, mixed> the block as kept
     *
     * @throws InvalidContentInput
     */
    public function block(mixed $block, int $index): array
    {
        if (! is_array($block)) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }

        return match ($block['type'] ?? null) {
            'paragraph' => $this->paragraph($block, $index),
            'heading' => $this->heading($block, $index),
            'list' => $this->list($block, $index),
            'image' => $this->image($block, $index),
            'table' => $this->table($block, $index),
            default => throw $this->refuse(InvalidContentInput::FORMAT, $index),
        };
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function paragraph(array $block, int $index): array
    {
        $this->keysAre($block, ['runs', 'type'], $index);

        return ['type' => 'paragraph', 'runs' => $this->runs($block['runs'], $index, false)];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function heading(array $block, int $index): array
    {
        $this->keysAre($block, ['level', 'runs', 'type'], $index);

        if (! in_array($block['level'], [2, 3], true)) {
            throw $this->refuse(InvalidContentInput::HEADING_LEVEL, $index);
        }

        return ['type' => 'heading', 'level' => $block['level'], 'runs' => $this->runs($block['runs'], $index, false)];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function list(array $block, int $index): array
    {
        $ordered = array_key_exists('ordered', $block);
        $this->keysAre($block, $ordered ? ['items', 'ordered', 'type'] : ['items', 'type'], $index);

        // Written only when true, so a bulleted list has one shape.
        if ($ordered && $block['ordered'] !== true) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }

        if (! is_array($block['items']) || ! array_is_list($block['items']) || $block['items'] === []) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }

        if (count($block['items']) > RichText::MAX_ITEMS) {
            throw $this->refuse(InvalidContentInput::TOO_MANY_ITEMS, $index, ['max' => RichText::MAX_ITEMS]);
        }

        $items = array_map(fn (mixed $item): array => $this->runs($item, $index, false), $block['items']);

        return $ordered ? ['type' => 'list', 'ordered' => true, 'items' => $items] : ['type' => 'list', 'items' => $items];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function image(array $block, int $index): array
    {
        $this->keysAre($block, ['alt', 'ref', 'type'], $index);

        if (! is_int($block['ref']) || $block['ref'] < 1 || $block['ref'] > 999_999) {
            throw $this->refuse(InvalidContentInput::IMAGE, $index);
        }

        if (! is_string($block['alt']) || trim($block['alt']) === '') {
            throw $this->refuse(InvalidContentInput::IMAGE, $index);
        }

        $alt = RichText::line($this->attribute, trim($block['alt']), $index);
        $this->count(mb_strlen($alt), $index);

        $this->images[] = $block['ref'];

        if (count($this->images) > RichText::MAX_IMAGES) {
            throw $this->refuse(InvalidContentInput::TOO_MANY_IMAGES, $index, ['max' => RichText::MAX_IMAGES]);
        }

        return ['type' => 'image', 'ref' => $block['ref'], 'alt' => $alt];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function table(array $block, int $index): array
    {
        $this->keysAre($block, ['head', 'rows', 'type'], $index);
        $head = $this->cells($block['head'], $index);
        $columns = count($head);

        if ($columns < RichText::MIN_COLUMNS || $columns > RichText::MAX_COLUMNS) {
            throw $this->refuse(InvalidContentInput::TABLE_COLUMNS, $index, ['min' => RichText::MIN_COLUMNS, 'max' => RichText::MAX_COLUMNS]);
        }

        if (! is_array($block['rows']) || ! array_is_list($block['rows'])) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }

        if ($block['rows'] === [] || count($block['rows']) > RichText::MAX_ROWS) {
            throw $this->refuse(InvalidContentInput::TABLE_ROWS, $index, ['max' => RichText::MAX_ROWS]);
        }

        $rows = [];

        foreach ($block['rows'] as $row) {
            $cells = $this->cells($row, $index);

            if (count($cells) !== $columns) {
                throw $this->refuse(InvalidContentInput::TABLE_ROW_COLUMNS, $index, ['found' => count($cells), 'expected' => $columns]);
            }

            $rows[] = $cells;
        }

        return ['type' => 'table', 'head' => $head, 'rows' => $rows];
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    private function cells(mixed $cells, int $index): array
    {
        if (! is_array($cells) || ! array_is_list($cells)) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }

        return array_map(fn (mixed $cell): array => $this->runs($cell, $index, true), $cells);
    }

    /**
     * A line of runs: each its words and its marks, side by side with the same marks joined.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidContentInput
     */
    private function runs(mixed $runs, int $index, bool $mayBeEmpty): array
    {
        if (! is_array($runs) || ! array_is_list($runs) || (! $mayBeEmpty && $runs === [])) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }

        /** @var list<array<string, mixed>> $kept */
        $kept = [];

        foreach ($runs as $run) {
            if (! is_array($run) || array_diff(array_keys($run), ['text', 'bold', 'link']) !== [] || ! array_key_exists('text', $run)) {
                throw $this->refuse(InvalidContentInput::FORMAT, $index);
            }

            $text = RichText::line($this->attribute, $run['text'], $index);
            $this->count(mb_strlen($text), $index);

            if (array_key_exists('bold', $run) && $run['bold'] !== true) {
                throw $this->refuse(InvalidContentInput::FORMAT, $index);
            }

            $link = null;

            if (array_key_exists('link', $run)) {
                $link = is_string($run['link']) ? RichText::link($run['link']) : null;

                if ($link === null) {
                    throw $this->refuse(InvalidContentInput::LINK, $index);
                }
            }

            $piece = ['text' => $text];

            if (($run['bold'] ?? false) === true) {
                $piece['bold'] = true;
            }

            if ($link !== null) {
                $piece['link'] = $link;
            }

            $last = array_key_last($kept);

            if ($last !== null && self::sameMarks($kept[$last], $piece)) {
                $kept[$last]['text'] = $kept[$last]['text'].$text;
            } else {
                $kept[] = $piece;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameMarks(array $a, array $b): bool
    {
        return ($a['bold'] ?? false) === ($b['bold'] ?? false) && ($a['link'] ?? null) === ($b['link'] ?? null);
    }

    private function count(int $characters, int $index): void
    {
        $this->characters += $characters;

        if ($this->characters > RichText::MAX_CHARACTERS) {
            throw $this->refuse(InvalidContentInput::TOO_LONG, $index, ['max' => RichText::MAX_CHARACTERS]);
        }
    }

    /**
     * Whether the block has exactly these keys, in any order: jsonb sorts an object's keys (lesson 38).
     *
     * @param  array<array-key, mixed>  $block
     * @param  list<string>  $keys  sorted
     */
    private function keysAre(array $block, array $keys, int $index): void
    {
        $actual = array_map('strval', array_keys($block));
        sort($actual);

        if ($actual !== $keys) {
            throw $this->refuse(InvalidContentInput::FORMAT, $index);
        }
    }

    /**
     * @param  array<string, int>  $values
     */
    private function refuse(string $rule, int $index, array $values = []): InvalidContentInput
    {
        return new InvalidContentInput($this->attribute, $rule, values: $values, block: $index);
    }
}
