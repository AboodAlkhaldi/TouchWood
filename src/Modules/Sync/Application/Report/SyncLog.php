<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Shared\Domain\ValueObject\StoreId;

/**
 * What the Sync report, the page of reads and a read's page show (sync.md §1.6, §4.1, §9.3 #19), read as
 * rows: every query names its store or its read, and each page is a fixed few queries however long. Lists
 * are keyset paged (pricing.md §4.3.1 "Show More"): a page after the last row shown, by a key a row keeps.
 *
 * @phpstan-type EntryRow array{id: int, kind: string, code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>, openedAt: string, seenAt: string}
 * @phpstan-type ReadRow array{id: string, storeId: string, kind: string, state: string, codes: list<string>|null, requestedById: string|null, pricesChanged: int, stockChanged: int, skipped: int, error: string|null, queuedAt: string, startedAt: string|null, finishedAt: string|null}
 * @phpstan-type LineRow array{variantId: string, code: string|null, before: string|null, after: string}
 * @phpstan-type ReportedRow array{kind: string, code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>}
 * @phpstan-type StatusRow array{provider: string|null, state: string|null, lastError: string|null, lastFullReadAt: string|null, lastReadAt: string|null, running: array{kind: string, at: string}|null, waiting: array{kind: string, at: string}|null}
 */
interface SyncLog
{
    /**
     * The store's open entries, the latest seen first, of one kind or all: those after the key given
     * (the last row's seen time and id), at most `$limit`.
     *
     * @param  array{string, string}|null  $after
     * @return list<EntryRow>
     */
    public function openEntries(StoreId $store, ?string $kind, ?array $after, int $limit): array;

    /**
     * How many entries the store has open, by kind - one query.
     *
     * @return array<string, int>
     */
    public function openByKind(StoreId $store): array;

    /** How many entries the store has open - the report's count. */
    public function openCount(StoreId $store): int;

    /**
     * How many entries are open over these stores - every store when null - one query: the count beside
     * the menu's entry.
     *
     * @param  list<StoreId>|null  $stores
     */
    public function openCountIn(?array $stores): int;

    /**
     * The store's reads, the latest asked first: those after the key given (the last row's queued time
     * and id), at most `$limit`.
     *
     * @param  array{string, string}|null  $after
     * @return list<ReadRow>
     */
    public function reads(StoreId $store, ?array $after, int $limit): array;

    /** How many reads the store has kept. */
    public function readsCount(StoreId $store): int;

    /**
     * @return ReadRow|null
     */
    public function read(string $readId): ?array;

    /**
     * What each read changed and skipped, counted from the lines and the record it wrote - for a failed
     * read, whose own counts were never written (Q30). One query however many.
     *
     * @param  list<string>  $readIds
     * @return array<string, array{prices: int, stock: int, skipped: int}>
     */
    public function countsWritten(array $readIds): array;

    /**
     * How many lines each section of a read holds - one query.
     *
     * @return array{prices: int, stock: int, reported: int}
     */
    public function sections(string $readId): array;

    /**
     * What a read changed of one kind, in the order written.
     *
     * @param  'PRICE'|'STOCK'  $kind
     * @return list<LineRow>
     */
    public function lines(string $readId, string $kind, int $page, int $perPage): array;

    /**
     * What a read reported, as it was that day.
     *
     * @return list<ReportedRow>
     */
    public function reported(string $readId, int $page, int $perPage): array;

    /**
     * A store's connection and its reads at a glance (S8, Q29) - one query: the provider and its state
     * (null when not wired), the failure kept, the last full read's start, the newest finished read's
     * end, and the read running or the first waiting, each with its kind and its start or when it was
     * asked.
     *
     * @return StatusRow
     */
    public function status(StoreId $store): array;

    /**
     * The stores where a Sync Now read waits (Q26) - one query.
     *
     * @return list<string>
     */
    public function nowWaiting(): array;
}
