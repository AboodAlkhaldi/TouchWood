<?php

declare(strict_types=1);

namespace Modules\Sync\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sync\Application\Command\PruneSyncLog\PruneSyncLogHandler;
use Modules\Sync\Application\Report\SyncDays;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\ValueObject\ReadFailure;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Modules\Sync\Public\Contracts\SyncApi;
use Modules\Sync\Public\Dto\SyncDayDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sync's contract for other modules (sync.md §2.2, amendment 1). No permission is checked: the caller's
 * use case checks its own.
 */
final readonly class SyncApiImpl implements SyncApi
{
    /** A read Sync failed itself, not the provider: it opens no Read Failed entry (sync.md §4.1.1, Q31). */
    private const array STOPPED = [ReadFailure::Unwired, ReadFailure::StoppedRewired, ReadFailure::Superseded];

    private const string MOMENT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private PlatformApi $platform,
        private SyncDays $days,
    ) {}

    public function readsAndProblemsPerDay(array $stores, string $fromDay, string $toDay, DateTimeImmutable $until): array
    {
        $first = self::day($fromDay);
        $last = self::day($toDay);

        if ($first > $last) {
            throw new InvalidSyncInput('day');
        }

        $zones = $this->zones($stores);
        // Older than this, a read or a closed entry may have been pruned already (sync.md §1.6).
        $keptSince = CarbonImmutable::now()->subDays(PruneSyncLogHandler::KEEP_DAYS);
        $windows = [];
        $complete = [];

        foreach ($zones as $storeId => $zone) {
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $name = $day->format('Y-m-d');
                $start = new DateTimeImmutable($name.' 00:00:00', $zone);

                if ($start >= $until) {
                    break;
                }

                $end = $start->modify('+1 day');
                $windows[] = [
                    'storeId' => $storeId,
                    'day' => $name,
                    'from' => $start->format(self::MOMENT),
                    'to' => ($end > $until ? $until : $end)->format(self::MOMENT),
                ];
                $complete[$storeId.' '.$name] = $start >= $keptSince;
            }
        }

        $reads = [];

        foreach ($this->days->reads($windows, array_map(static fn (ReadFailure $f): string => $f->value, self::STOPPED)) as $row) {
            $reads[$row['storeId'].' '.$row['day']][] = $row;
        }

        $problems = [];

        foreach ($this->days->problemsOpened($windows) as $row) {
            $problems[$row['storeId'].' '.$row['day']][$row['kind']] = $row['opened'];
        }

        return array_map(fn (array $window): SyncDayDto => $this->answer(
            $window['storeId'],
            $window['day'],
            $reads[$window['storeId'].' '.$window['day']] ?? [],
            $problems[$window['storeId'].' '.$window['day']] ?? [],
            $complete[$window['storeId'].' '.$window['day']],
        ), $windows);
    }

    /**
     * @param  list<array{storeId: string, day: string, kind: string, outcome: string, reads: int, prices: int, stock: int, skipped: int}>  $reads
     * @param  array<string, int>  $problems
     */
    private function answer(string $storeId, string $day, array $reads, array $problems, bool $complete): SyncDayDto
    {
        $none = array_fill_keys(array_map(static fn (ReadKind $k): string => $k->value, ReadKind::cases()), 0);
        $byOutcome = ['DONE' => $none, 'FAILED' => $none, 'STOPPED' => $none];
        $prices = 0;
        $stock = 0;
        $skipped = 0;

        foreach ($reads as $row) {
            $byOutcome[$row['outcome']][$row['kind']] = $row['reads'];
            $prices += $row['prices'];
            $stock += $row['stock'];
            $skipped += $row['skipped'];
        }

        $opened = [];

        foreach (ReportKind::cases() as $kind) {
            $opened[$kind->value] = $problems[$kind->value] ?? 0;
        }

        return new SyncDayDto(
            $storeId,
            $day,
            $byOutcome['DONE'],
            $byOutcome['FAILED'],
            $byOutcome['STOPPED'],
            $prices,
            $stock,
            $skipped,
            $opened,
            $complete,
        );
    }

    /**
     * Each store asked, once, in the order given, with its time zone - an off store too (§1.1 rule 8).
     *
     * @param  list<StoreId>  $stores
     * @return array<string, DateTimeZone>
     */
    private function zones(array $stores): array
    {
        $known = [];

        foreach ($this->platform->allStores() as $store) {
            $known[strtolower($store->id)] = $store->timezone;
        }

        $zones = [];

        foreach ($stores as $store) {
            $zone = $known[$store->value] ?? throw new InvalidSyncInput('store');
            $zones[$store->value] ??= new DateTimeZone($zone);
        }

        return $zones;
    }

    /** A calendar day, `Y-m-d` exactly; anything else refused. */
    private static function day(string $day): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw new InvalidSyncInput('day');
        }

        return $parsed;
    }
}
