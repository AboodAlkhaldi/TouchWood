<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Ops\Application\Command\DeliverDailyReport\DeliverDailyReport;
use Modules\Ops\Application\Command\DeliverDailyReport\DeliverDailyReportHandler;
use Modules\Ops\Application\Command\SendDailyReportEmail\SendDailyReportEmail;
use Modules\Ops\Application\Command\SendDailyReportEmail\SendDailyReportEmailHandler;
use Modules\Ops\Application\Command\SetDailyReportEmail\SetDailyReportEmail;
use Modules\Ops\Application\Command\SetDailyReportEmail\SetDailyReportEmailHandler;
use Modules\Ops\Application\DailyReport\DailyReportEmailQueue;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ViewDailyReportEmails\ViewDailyReportEmails;
use Modules\Ops\Application\Query\ViewDailyReportEmails\ViewDailyReportEmailsHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Modules\Ops\Infrastructure\Queue\DeliverDailyReportJob;
use Modules\Ops\Infrastructure\Queue\SendDailyReportEmailJob;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\DailyReportFixtures as Dx;

use function Pest\Laravel\seed;

/*
| The daily report by email (ops.md §1.11, §7, §8): off by default; switched on at a time read in the
| store's time zone - every store's in the base store's (Riyadh); then exactly the button's report at
| that time, made on the person's behalf and sent to them with the PDF attached, once; a provider failure
| tried again by the queue.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake(LocalReportFiles::DISK);
    Dx::fakeRenderer();
});

function dailyEmailSet(?string $store, bool $on, string $at): DailyReportEmail
{
    return app(SetDailyReportEmailHandler::class)->handle(new SetDailyReportEmail($store === null ? null : Fx::storeId($store), $on, $at));
}

it('is off until the person switches it on, then queues the first at the time in the store\'s zone', function () {
    Queue::fake();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['ae']);
    Fx::actAsStaff($staff);

    expect(app(ViewDailyReportEmailsHandler::class)->handle(new ViewDailyReportEmails))->toBe([]);

    $on = dailyEmailSet('ae', true, '07:30');
    $moved = dailyEmailSet('ae', true, '21:15');
    $off = dailyEmailSet('ae', false, '21:15');

    expect([$on->version, $moved->version, $off->version, $off->id === $on->id, $off->isOn])->toBe([1, 2, 3, true, false])
        ->and(DB::table('ops.daily_report_settings')->count())->toBe(1);
    Queue::assertPushed(SendDailyReportEmailJob::class, 2);
    Queue::assertPushed(SendDailyReportEmailJob::class, fn (SendDailyReportEmailJob $job): bool => $job->version === 1
        && CarbonImmutable::parse($job->due)->setTimezone('Asia/Dubai')->format('H:i') === '07:30'
        && CarbonImmutable::parse($job->due)->isFuture());
});

it('reads the time in the store\'s zone, and every store\'s in the base store\'s, the next day once it has passed', function () {
    $at = new DateTimeImmutable('2026-10-30 05:00:00 UTC');
    $email = static fn (string $time): DailyReportEmail => new DailyReportEmail('01jbxqz2y8k4n6p9r3t5v7w1xa', '01jbxqz2y8k4n6p9r3t5v7w1xb', null, true, $time, 1, $at, $at);
    $utc = static fn (DateTimeImmutable $moment): string => $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');

    // 05:00 UTC is 08:00 in Riyadh and 09:00 in Dubai.
    expect($utc($email('08:30')->nextAfter($at, new DateTimeZone('Asia/Riyadh'))))->toBe('2026-10-30 05:30')
        ->and($utc($email('08:30')->nextAfter($at, new DateTimeZone('Asia/Dubai'))))->toBe('2026-10-31 04:30')
        ->and($utc($email('08:00')->nextAfter($at, new DateTimeZone('Asia/Riyadh'))))->toBe('2026-10-31 05:00')
        ->and(fn () => $email('24:00'))->toThrow(InvalidOpsInput::class)
        ->and(fn () => $email('7:30'))->toThrow(InvalidOpsInput::class);
});

it('lets only those who may make the report switch its email on, every store\'s only a super admin', function () {
    Queue::fake();
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']));

    expect(fn () => dailyEmailSet('ae', true, '07:30'))->toThrow(Unauthorized::class)
        ->and(fn () => dailyEmailSet(null, true, '07:30'))->toThrow(Unauthorized::class)
        ->and(fn () => dailyEmailSet('sa', true, 'soon'))->toThrow(InvalidOpsInput::class);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(dailyEmailSet(null, true, '06:00')->store)->toBeNull();
    Queue::assertPushed(SendDailyReportEmailJob::class, 1);
});

it('makes the button\'s report at the time, on the person\'s behalf, queues tomorrow\'s, and sends the PDF to them once', function () {
    Queue::fake();
    $emails = CapturedEmails::install();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $setting = dailyEmailSet('sa', true, '07:30');
    $due = $setting->nextDueAt ?? throw new LogicException('Switched on without a moment.');

    Fx::actAs(Actor::system(Actor::staff($staff)));
    $report = app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($setting->id, $setting->version, $due));

    // Made as at its moment: the button's report at 07:30.
    expect([$report?->madeBy, $report?->store?->value, $report?->pastDay, $report?->emailedAt, $report?->madeAt == $due])->toBe([$staff, Fx::storeId('sa'), false, null, true]);
    Queue::assertPushed(SendDailyReportEmailJob::class, fn (SendDailyReportEmailJob $job): bool => CarbonImmutable::parse($job->due)->greaterThan($due));
    Queue::assertPushed(DeliverDailyReportJob::class, fn (DeliverDailyReportJob $job): bool => $job->reportId === $report?->id);

    $first = app(DeliverDailyReportHandler::class)->handle(new DeliverDailyReport((string) $report?->id));
    $again = app(DeliverDailyReportHandler::class)->handle(new DeliverDailyReport((string) $report?->id));
    $sent = $emails->sent;

    expect([$first, $again, count($sent)])->toBe([true, false, 1])
        ->and($sent[0]->toEmail)->toBe("{$staff}@example.test")
        ->and($sent[0]->subject)->toContain('Daily report')
        ->and(array_map(static fn ($file): string => $file->name, $sent[0]->attachments))->toBe(['daily-report-sa-'.$report?->day.'.pdf'])
        ->and($sent[0]->attachments[0]->content)->toBe(Storage::disk(LocalReportFiles::DISK)->get((string) $report?->file))
        ->and(DB::table('ops.daily_reports')->where('id', $report?->id)->value('emailed_at'))->not->toBeNull();
});

it('stops an email switched off or changed since, and skips a day the person may no longer make it', function () {
    Queue::fake();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $old = dailyEmailSet('sa', true, '07:30');
    $new = dailyEmailSet('sa', true, '08:00');
    $due = $new->nextDueAt ?? throw new LogicException('Switched on without a moment.');
    Fx::actAs(Actor::system(Actor::staff($staff)));

    expect(app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($old->id, $old->version, $old->nextDueAt ?? $due)))->toBeNull();
    Queue::assertPushed(SendDailyReportEmailJob::class, 2);

    // The store taken away: nothing made today, tomorrow's still queued.
    Fx::assign($staff, Fx::roleOf($staff), ['ae']);
    Fx::actAs(Actor::system(Actor::staff($staff)));
    expect(app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($new->id, $new->version, $due)))->toBeNull()
        ->and(DB::table('ops.daily_reports')->count())->toBe(0);
    Queue::assertPushed(SendDailyReportEmailJob::class, 3);
});

it('carries the person in the queued job, so the worker makes their report on their behalf', function () {
    config(['queue.default' => 'database']);
    $emails = CapturedEmails::install();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    dailyEmailSet('sa', true, '07:30');
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['requestedBy'] ?? null)->toBe(['type' => 'STAFF', 'id' => $staff]);

    // Its time come: the worker runs it, then the delivery it queued.
    DB::table('jobs')->update(['available_at' => CarbonImmutable::now()->subSecond()->getTimestamp()]);
    Fx::asSystem(function (): void {
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
    });

    expect(DB::table('ops.daily_reports')->pluck('made_by')->all())->toBe([$staff])
        ->and(count($emails->sent))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('starts one email however often it is switched on: a double submit, a retried job, never a second', function () {
    Queue::fake();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $first = dailyEmailSet('sa', true, '07:30');
    $second = dailyEmailSet('sa', true, '07:30');
    Fx::actAs(Actor::system(Actor::staff($staff)));
    $send = static fn (DailyReportEmail $email): mixed => app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($email->id, $email->version, $email->nextDueAt ?? new DateTimeImmutable));

    expect([$first->id === $second->id, $first->version, $second->version])->toBe([true, 1, 2])
        ->and(DB::table('ops.daily_report_settings')->count())->toBe(1)
        // The first submit's job stops; the second's goes; the same job again (queue:retry) stops.
        ->and($send($first))->toBeNull()
        ->and($send($second))->not->toBeNull()
        ->and($send($second))->toBeNull()
        ->and(DB::table('ops.daily_reports')->count())->toBe(1);
    // Two from the submits, one next day from the job that went.
    Queue::assertPushed(SendDailyReportEmailJob::class, 3);
});

it('sends a late worker\'s missed report once, as at its moment, and goes on from after now', function () {
    Queue::fake();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $setting = dailyEmailSet('sa', true, '07:30');
    // The worker was down: the moment was yesterday at 07:30, picked up now, after midnight.
    $missed = CarbonImmutable::now('Asia/Riyadh')->subDay()->setTime(7, 30)->toDateTimeImmutable();
    DB::table('ops.daily_report_settings')->where('id', $setting->id)->update(['next_due_at' => $missed->format('Y-m-d H:i:sP')]);
    Fx::actAs(Actor::system(Actor::staff($staff)));

    $report = app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($setting->id, $setting->version, $missed));
    $again = app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($setting->id, $setting->version, $missed));
    $next = CarbonImmutable::parse((string) DB::table('ops.daily_report_settings')->where('id', $setting->id)->value('next_due_at'));

    expect([$report?->day, $report?->madeAt == $missed, $again])->toBe([$missed->format('Y-m-d'), true, null])
        ->and($next->isFuture())->toBeTrue()
        ->and($next->setTimezone('Asia/Riyadh')->format('H:i'))->toBe('07:30');
    Queue::assertPushed(SendDailyReportEmailJob::class, fn (SendDailyReportEmailJob $job): bool => CarbonImmutable::parse($job->due)->equalTo($next));
});

it('ends the email of a cancelled account and switches it off, and keeps a disabled one\'s, sending nothing', function (string $status, bool $stillOn, int $queued) {
    Queue::fake();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $setting = dailyEmailSet('sa', true, '07:30');
    // A cancelled account keeps no password (access.md: staff_users_cancelled_has_no_password).
    DB::table('access.staff_users')->where('id', $staff)->update(['status' => $status, 'password' => $status === 'CANCELLED' ? null : DB::raw('password')]);
    Fx::actAs(Actor::system(Actor::staff($staff)));

    expect(app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($setting->id, $setting->version, $setting->nextDueAt ?? new DateTimeImmutable)))->toBeNull()
        ->and(DB::table('ops.daily_reports')->count())->toBe(0)
        ->and((bool) DB::table('ops.daily_report_settings')->where('id', $setting->id)->value('is_on'))->toBe($stillOn);
    Queue::assertPushed(SendDailyReportEmailJob::class, $queued);
})->with([
    'cancelled' => ['CANCELLED', false, 1],
    'disabled' => ['DISABLED', true, 2],
]);

it('keeps the moment where it was when the next email cannot be queued: the job fails, it does not end quietly', function () {
    Queue::fake();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $setting = dailyEmailSet('sa', true, '07:30');
    $due = $setting->nextDueAt ?? throw new LogicException('Switched on without a moment.');
    app()->bind(DailyReportEmailQueue::class, fn () => new class implements DailyReportEmailQueue
    {
        public function send(string $settingId, int $version, DateTimeImmutable $at): void
        {
            throw new RuntimeException('The queue could not be written.');
        }

        public function deliver(string $reportId): void {}
    });
    Fx::actAs(Actor::system(Actor::staff($staff)));

    expect(fn () => app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($setting->id, $setting->version, $due)))->toThrow(RuntimeException::class)
        ->and(CarbonImmutable::parse((string) DB::table('ops.daily_report_settings')->where('id', $setting->id)->value('next_due_at'))->equalTo($due))->toBeTrue()
        ->and(DB::table('ops.daily_reports')->count())->toBe(0);
});

it('writes the next email into the jobs table inside the transaction, not after it', function () {
    config(['queue.default' => 'database']);
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $setting = dailyEmailSet('sa', true, '07:30');
    $due = $setting->nextDueAt ?? throw new LogicException('Switched on without a moment.');
    DB::table('jobs')->delete();

    // Inside a transaction that is then rolled back, the job is already there: written before the commit.
    DB::beginTransaction();
    app(DailyReportEmailQueue::class)->send($setting->id, $setting->version, $due);
    $inside = DB::table('jobs')->count();
    DB::rollBack();

    expect([$inside, DB::table('jobs')->count()])->toBe([1, 0]);
});
