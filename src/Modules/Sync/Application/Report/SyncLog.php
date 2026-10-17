<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Shared\Domain\ValueObject\StoreId;

/**
 * What the Sync report and the page of reads show (sync.md §1.6, §9.3 #19), read as rows: every query
 * names its store, and each page is a few queries however long.
 *
 * @phpstan-type EntryRow array{id: int, kind: string, code: string|null, variantId: string|null, saleId: string|null, readId: string|null, detail: array<string, mixed>, openedAt: string, seenAt: string}
 * @phpstan-type ReadRow array{id: string, storeId: string, kind: string, state: string, codes: list<string>|null, requestedById: string|null, pricesChanged: int, stockChanged: int, skipped: int, error: string|null, queuedAt: string, startedAt: string|null, finishedAt: string|null}
 * @phpstan-type LineRow array{kind: string, variantId: string, code: string|null, before: string|null, after: string}
 */
interface SyncLog
{
    /**
     * The store's open entries, the latest seen first.
     *
     * @return array{list<EntryRow>, int} a page of them, and how many there are
     */
    public function openEntries(StoreId $store, ?string $kind, int $page, int $perPage): array;

    /** How many entries the store has open - the report's count. */
    public function openCount(StoreId $store): int;

    /**
     * The store's reads, the latest asked first.
     *
     * @return array{list<ReadRow>, int} a page of them, and how many there are
     */
    public function reads(StoreId $store, int $page, int $perPage): array;

    /**
     * @return ReadRow|null
     */
    public function read(string $readId): ?array;

    /**
     * @return list<LineRow> what the read changed, prices first, in the order written
     */
    public function lines(string $readId): array;

    /**
     * @return list<EntryRow> what the read reported - the entries it last saw, open or closed since
     */
    public function entriesOf(string $readId): array;
}
