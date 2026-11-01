<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SendDailyReportEmail;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\DailyReport\DailyReportEmailQueue;
use Modules\Ops\Application\DailyReport\DailyReportMaker;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Ops\Domain\Repository\DailyReports;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The daily report by email, at the time the person chose** (ops.md §1.11): exactly the report the
 * button would have given at that moment - today, midnight until then - made in a queued job on the
 * person's behalf.
 *
 * - **One email per moment.** The setting holds the one moment the email may go next. This job goes on
 *   only if its moment is that one, and moves it to the next in the same statement; the next job is
 *   written to the database queue inside that same transaction (`beforeCommit`), so the moment and its
 *   job commit together or not at all - a double submit or a race stops here, and a failed push rolls the
 *   move back, so this job fails (into the failed jobs) rather than ending quietly.
 * - **A retry makes the same report.** The moment moves on before the making, so tomorrow's email never
 *   waits on today's failure. A job retried from the failed jobs, for a moment already passed in the same
 *   version, skips the move and makes that moment's report, as at that moment, if it is not kept yet; the
 *   report is kept once per setting and moment, with its delivery in the same transaction (DailyReportMaker),
 *   so a retry after it was made does nothing, and nothing is ever sent twice.
 * - **Late is still that day's.** The report is made as at the moment due, and the next is the first time
 *   after both that moment and now: a worker that was down sends the missed day once, never two.
 * - A setting switched off or changed since (another version) stops here; the change queued its own.
 * - A person **gone** - no account, or one cancelled - ends the email and switches it off. One **disabled**,
 *   or who may no longer make the report, is sent nothing that day; the email goes on, and sends again when
 *   they may.
 */
final readonly class SendDailyReportEmailHandler
{
    /** `ops.report.daily` (ops.md §3), in the store or, for every store's report, all stores. */
    public const string PERMISSION = DailyReportPeople::PERMISSION;

    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReports $reports,
        private DailyReportMaker $maker,
        private DailyReportEmailQueue $queue,
        private ConnectionInterface $db,
    ) {}

    /** @return DailyReportFile|null the report made, none when nothing was due */
    public function handle(SendDailyReportEmail $command): ?DailyReportFile
    {
        $setting = $this->reports->emailById($command->settingId);
        if ($setting === null || ! $setting->isOn || $setting->version !== $command->version) {
            return null;
        }

        $this->authorizer->authorize(self::PERMISSION, $setting->store === null ? PermissionScope::allStores() : PermissionScope::store($setting->store));
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $staff = $this->people->staff($setting->staffId);
        if ($staff === null || $staff->status === StaffStatus::Cancelled) {
            $this->reports->switchEmailOff($setting->id, $setting->version, $now);

            return null;
        }

        $store = $setting->store === null ? null : $this->people->store($setting->store->value);
        // Only a win moves the moment within a version, so one already past it was won by this job's first try.
        if ($setting->nextDueAt !== null && $setting->nextDueAt > $command->due) {
            if ($this->reports->emailMade($setting->id, $command->due)) {
                return null;
            }
        } elseif (! $this->won($setting, $command, $store, $now)) {
            return null;
        }

        try {
            $person = $this->people->person();
            if ($person->id !== $setting->staffId) {
                return null;
            }
            $store === null ? $this->people->requireAllStores($person) : $this->people->requireStore($store->storeId());
        } catch (Unauthorized) {
            return null;
        }

        return $store === null
            ? $this->maker->makeForEmail($person, null, $this->people->everyStore(), $this->people->allStoresZone(), $command->due, $setting->id)
            : $this->maker->makeForEmail($person, $store, [$store], $store->timezone, $command->due, $setting->id);
    }

    /** Moves the moment on, with the next job in the same transaction: false when another job has it. */
    private function won(DailyReportEmail $setting, SendDailyReportEmail $command, ?StoreDto $store, DateTimeImmutable $now): bool
    {
        $next = $setting->nextAfter(max($command->due, $now), new DateTimeZone($this->people->zone($store)));

        return $this->db->transaction(function () use ($setting, $command, $next): bool {
            if (! $this->reports->advanceEmail($setting->id, $setting->version, $command->due, $next)) {
                return false;
            }
            $this->queue->send($setting->id, $setting->version, $next);

            return true;
        });
    }
}
