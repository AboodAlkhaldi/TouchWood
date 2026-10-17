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
     * once the caller's transaction commits. A full read of a kind already waiting is not queued twice:
     * that one's id is answered.
     *
     * @param  list<string>|null  $codes  a notified or one-code read's codes
     * @return string its id
     */
    public function queue(StoreId $store, ReadKind $kind, ?array $codes = null): string;

    /** The store's waiting reads dropped - it was unwired (sync.md §1.8): failed, saying why. */
    public function dropQueued(StoreId $store, string $why): int;

    /**
     * The read taken from the queue to run: none when it is not waiting any more, or another read of
     * its store runs - it then waits for that one to finish (one read per store at a time, §1.3).
     */
    public function claim(string $readId): ?Read;

    /** A read running since before this time is taken as dead - its worker gone - and failed. */
    public function failStale(StoreId $store, DateTimeImmutable $runningSince): int;

    /** Back in the queue, its attempt failed and to be tried again (§1.7). */
    public function requeue(string $readId): void;

    public function finish(string $readId, int $pricesChanged, int $stockChanged, int $skipped): void;

    public function fail(string $readId, string $why): void;

    /** The store's oldest waiting read, if any, its job queued again: the next one's turn. */
    public function startNext(StoreId $store): void;

    public function storeOf(string $readId): ?string;
}
