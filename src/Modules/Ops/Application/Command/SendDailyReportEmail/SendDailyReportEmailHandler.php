<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SendDailyReportEmail;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\DailyReport\DailyReportEmailQueue;
use Modules\Ops\Application\DailyReport\DailyReportMaker;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Ops\Domain\Repository\DailyReports;
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
 *   job commit together or not at all - a double submit, a race or a job tried again stops here, and a
 *   failed push rolls the move back, so this job fails (into the failed jobs) rather than ending quietly.
 * - **Late is still that day's.** The report is made as at the moment due, and the next is the first time
 *   after both that moment and now: a worker that was down sends the missed day once, never two.
 * - A setting switched off or changed since (another version) stops here; the change queued its own.
 * - A person **gone** - no account, or one cancelled - ends the email and switches it off. One **disabled**,
 *   or who may no longer make the report, is sent nothing that day; the email goes on, and sends again when
 *   they may.
 */
final readonly class SendDailyReportEmailHandler
{
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

        $this->authorizer->authorize(DailyReportPeople::PERMISSION, $setting->store === null ? PermissionScope::allStores() : PermissionScope::store($setting->store));
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $staff = $this->people->staff($setting->staffId);
        if ($staff === null || $staff->status === StaffStatus::Cancelled) {
            $this->reports->switchEmailOff($setting->id, $setting->version, $now);

            return null;
        }

        $store = $setting->store === null ? null : $this->people->store($setting->store->value);
        $next = $setting->nextAfter(max($command->due, $now), new DateTimeZone($this->people->zone($store)));
        $won = $this->db->transaction(function () use ($setting, $command, $next): bool {
            if (! $this->reports->advanceEmail($setting->id, $setting->version, $command->due, $next)) {
                return false;
            }
            $this->queue->send($setting->id, $setting->version, $next);

            return true;
        });
        if (! $won) {
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

        $report = $store === null
            ? $this->maker->make($person, null, $this->people->everyStore(), null, $this->people->allStoresZone(), $command->due)
            : $this->maker->make($person, $store, [$store], null, $store->timezone, $command->due);
        $this->queue->deliver($report->id);

        return $report;
    }
}
