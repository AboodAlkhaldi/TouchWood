<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use DateTimeImmutable;
use Modules\Sync\Domain\Model\Read;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * The log of each store's reads (sync.md §5 `sync.reads`).
 */
interface Reads
{
    /**
     * A read waiting its turn, asked by whoever acts now when staff do (sync.md §1.3), its job queued
     * once the caller's transaction commits. A read of a kind already waiting is not queued twice: a
     * notice's or a code's adds its codes to the one waiting; that one's job is queued again (harmless:
     * only one claim wins), and its id answered.
     *
     * @param  list<string>|null  $codes  a notified or one-code read's codes
     * @return string its id
     */
    public function queue(StoreId $store, ReadKind $kind, ?array $codes = null): string;

    /**
     * The store's reads not finished - waiting or running - dropped: it was unwired (sync.md §1.8).
     * A running one finds it so before its next batch and stops.
     */
    public function dropUnfinished(StoreId $store, string $why): int;

    /**
     * The read taken to run: waiting, or its own run that started before `$deadBefore` (its worker went
     * away). None when it is finished, or another read of its store runs - it then waits for that one
     * (one read per store at a time, §1.3).
     */
    public function claim(string $readId, DateTimeImmutable $deadBefore): ?Read;

    /**
     * The store's other reads running since before this time - their workers gone -; never `$except`, the
     * read about to take itself back. Each is then failed on its own (`failIfStale`), with its report.
     *
     * @return list<string> their ids
     */
    public function stale(StoreId $store, DateTimeImmutable $runningSince, string $except): array;

    /** Failed, saying so, if it still runs since before this time - false when it does not (finished, or taken back). */
    public function failIfStale(string $readId, DateTimeImmutable $runningSince, string $why): bool;

    /** Whether the read still runs: not failed meanwhile by unwiring or a takeover. */
    public function isRunning(string $readId): bool;

    /**
     * Back in the queue, its attempt failed and to be tried again (§1.7) - or failed as superseded when
     * a read of the same kind already waits, that one's job queued.
     */
    public function requeue(string $readId): void;

    /** Done, with its counts - false when it was no longer running (unwired, or taken over). */
    public function finish(string $readId, int $pricesChanged, int $stockChanged, int $skipped): bool;

    /** When the read started, while it runs - null when it does not. */
    public function runningSince(string $readId): ?DateTimeImmutable;

    /** Failed, saying why - if it was not finished already; false when it was. */
    public function fail(string $readId, string $why): bool;

    /** The store's oldest waiting read, if any, its job queued again: the next one's turn. */
    public function startNext(StoreId $store): void;

    public function storeOf(string $readId): ?string;

    /** Reads finished before this time deleted, their lines with them (sync.md §5: kept 90 days); how many. */
    public function pruneFinishedBefore(DateTimeImmutable $before): int;
}
