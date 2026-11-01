<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use DateTimeImmutable;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Domain\Model\DailyReportFile;
use Shared\Domain\ValueObject\StoreId;

/** The daily reports kept and the people's emails of them (ops.md §1.11, §5). */
interface DailyReports
{
    public function add(DailyReportFile $report): void;

    /**
     * Keeps the report the email made at its moment: false, keeping nothing, when that moment's is kept
     * already - a job run twice makes one report.
     */
    public function addForEmail(DailyReportFile $report, string $settingId, DateTimeImmutable $due): bool;

    /** Whether the email's report of that moment is kept. */
    public function emailMade(string $settingId, DateTimeImmutable $due): bool;

    public function find(string $id): ?DailyReportFile;

    /**
     * Newest first, a page at a time, after the row `$after` names.
     *
     * @param  list<StoreId>|null  $stores  the stores' reports to list; null for every store's
     * @param  bool  $allStores  whether every store's own reports (no store) are listed too
     * @return list<DailyReportFile>
     */
    public function page(?array $stores, bool $allStores, ?string $after, int $limit): array;

    public function markEmailed(string $id, DateTimeImmutable $at): void;

    /**
     * Removes the rows made before the moment.
     *
     * @return list<string> their files, for the caller to delete
     */
    public function prune(DateTimeImmutable $before): array;

    public function email(string $staffId, ?StoreId $store): ?DailyReportEmail;

    public function emailById(string $id): ?DailyReportEmail;

    /** @return list<DailyReportEmail> the person's, the all-stores one first */
    public function emailsOf(string $staffId): array;

    /**
     * Switches the person's email for the store (null: every store's) on or off, or moves its time - in
     * one statement: the row made or changed, its version one more, its next moment set (null when off).
     */
    public function setEmail(string $newId, string $staffId, ?StoreId $store, bool $isOn, string $sendAt, ?DateTimeImmutable $nextDueAt, DateTimeImmutable $now): DailyReportEmail;

    /**
     * Moves the email's next moment from `$due` to `$next` - only if it is still on, still that version and
     * still due at `$due`: the one job that wins goes on, any other (a duplicate) stops.
     */
    public function advanceEmail(string $id, int $version, DateTimeImmutable $due, DateTimeImmutable $next): bool;

    /** Switches the email off for good, if it is still that version (its person is gone). */
    public function switchEmailOff(string $id, int $version, DateTimeImmutable $now): void;
}
