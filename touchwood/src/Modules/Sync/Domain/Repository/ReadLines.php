<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

/**
 * What each read changed (sync.md §9.3 #19, `sync.read_lines`): kept with the read, deleted with it.
 */
interface ReadLines
{
    /**
     * One batch's changes, in one statement.
     *
     * @param  'PRICE'|'STOCK'  $kind
     * @param  list<array{variantId: string, code: string|null, before: string|int|null, after: string|int}>  $lines
     */
    public function add(string $readId, string $kind, array $lines): void;

    /** How many of the kind it changed, over every attempt. */
    public function count(string $readId, string $kind): int;
}
