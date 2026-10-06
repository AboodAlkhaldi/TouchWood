<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\WordPair;

interface WordPairRepository
{
    public function nextId(): string;

    public function find(string $pairId): ?WordPair;

    /** Whether this pair, in its kept order, is already in the list. */
    public function exists(WordPair $pair): bool;

    public function add(WordPair $pair): void;

    public function delete(string $pairId): void;

    /**
     * @return list<WordPair>
     */
    public function all(): array;
}
