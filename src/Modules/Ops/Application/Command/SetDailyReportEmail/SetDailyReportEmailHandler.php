<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SetDailyReportEmail;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Modules\Ops\Application\DailyReport\DailyReportEmailQueue;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Switches the person's daily report email on or off, or moves its time (ops.md §1.11; off by default).
 * Every change makes a new version, in one statement; switched on, the next email is queued on the
 * person's behalf for the one moment stored, and one queued for an older version stops when it comes.
 */
final readonly class SetDailyReportEmailHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReports $reports,
        private DailyReportEmailQueue $queue,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput */
    public function handle(SetDailyReportEmail $command): DailyReportEmail
    {
        $person = $this->people->person();
        $store = null;
        if ($command->store === null) {
            $this->authorizer->authorize(DailyReportPeople::PERMISSION, PermissionScope::allStores());
            $this->people->requireAllStores($person);
        } else {
            $store = $this->people->store($command->store);
            $this->authorizer->authorize(DailyReportPeople::PERMISSION, PermissionScope::store($store->storeId()));
            $this->people->requireStore($store->storeId());
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $sendAt = trim($command->sendAt);
        if (preg_match(DailyReportEmail::TIME, $sendAt) !== 1) {
            throw new InvalidOpsInput('send_at', 'a time of day, HH:MM');
        }
        $next = $command->on ? DailyReportEmail::nextAt($sendAt, $now, new DateTimeZone($this->people->zone($store))) : null;
        $email = $this->reports->setEmail(strtolower((string) Str::ulid()), $person->id, $store?->storeId(), $command->on, $sendAt, $next, $now);

        if ($email->isOn && $email->nextDueAt !== null) {
            $this->queue->send($email->id, $email->version, $email->nextDueAt);
        }

        return $email;
    }
}
