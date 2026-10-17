<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

/**
 * One read as the page of reads shows it (sync.md §9.3 #19): when, which kind, who asked, how it ended,
 * how many prices and stock numbers it changed and how many it skipped.
 */
final readonly class ReadView
{
    /**
     * @param  list<string>|null  $codes
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $state,
        public ?array $codes,
        public ?string $requestedById,
        public int $pricesChanged,
        public int $stockChanged,
        public int $skipped,
        public ?string $error,
        public string $queuedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {}

    /**
     * @param  array{id: string, storeId: string, kind: string, state: string, codes: list<string>|null, requestedById: string|null, pricesChanged: int, stockChanged: int, skipped: int, error: string|null, queuedAt: string, startedAt: string|null, finishedAt: string|null}  $row
     */
    public static function of(array $row): self
    {
        return new self($row['id'], $row['kind'], $row['state'], $row['codes'], $row['requestedById'], $row['pricesChanged'], $row['stockChanged'], $row['skipped'], $row['error'], $row['queuedAt'], $row['startedAt'], $row['finishedAt']);
    }
}
