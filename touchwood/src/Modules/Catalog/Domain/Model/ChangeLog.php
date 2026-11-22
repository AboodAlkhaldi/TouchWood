<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

/**
 * What changed in a model since it was read, column by column, with the value each had before — so
 * a handler writes exactly what changed to the audit log, by value (a list's words are the
 * business's, never a person's), and nothing when nothing did. Every Catalog list model keeps one,
 * and answers its columns' values now through `snapshot()`.
 */
final class ChangeLog
{
    /** @var array<string, string|int|bool|null> column => its value before the first change */
    private array $before = [];

    public function record(string $column, string|int|bool|null $before, string|int|bool|null $after): void
    {
        if ($before === $after) {
            return;
        }

        if (! array_key_exists($column, $this->before)) {
            $this->before[$column] = $before;
        }
    }

    /**
     * @return array<string, string|int|bool|null> column => its value before, emptied on reading
     */
    public function pull(): array
    {
        $before = $this->before;
        $this->before = [];

        return $before;
    }
}
