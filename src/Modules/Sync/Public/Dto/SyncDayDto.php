<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Dto;

/**
 * One store's Sync day (sync.md amendment 1): the reads that finished that day, counted per read kind
 * (`NOTIFIED`, `HOURLY`, `NIGHTLY`, `NOW`, `CODE`, every kind present) as done, failed or stopped by Sync
 * itself; what those reads changed and skipped, in total; and the report entries opened that day, per
 * report kind (the eight of sync.md §1.6, every kind present). `day` is the store's own calendar day,
 * `Y-m-d`. `complete` is false for a day that began more than 90 days ago, when Sync no longer keeps all
 * of it (§1.6).
 */
final readonly class SyncDayDto
{
    /**
     * @param  array<string, int>  $readsDone  by read kind
     * @param  array<string, int>  $readsFailed  by read kind
     * @param  array<string, int>  $readsStopped  by read kind
     * @param  array<string, int>  $problemsOpened  by report kind
     */
    public function __construct(
        public string $storeId,
        public string $day,
        public array $readsDone,
        public array $readsFailed,
        public array $readsStopped,
        public int $pricesChanged,
        public int $stockChanged,
        public int $skipped,
        public array $problemsOpened,
        public bool $complete,
    ) {}
}
