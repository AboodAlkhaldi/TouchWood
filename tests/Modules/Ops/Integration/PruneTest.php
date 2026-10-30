<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Application\Command\PruneOpsLog\PruneOpsLog;
use Modules\Ops\Application\Command\PruneOpsLog\PruneOpsLogHandler;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Infrastructure\Queue\PruneOpsLogJob;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;

use function Pest\Laravel\seed;

/*
| What Ops keeps twelve months (ops.md §1.2, §5): inbox entries, sent messages, handled events - removed
| each night by the system alone; and every listener run once per event (§6).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function pruneSent(CarbonImmutable $at): string
{
    $id = strtolower((string) Str::ulid());
    app(SentMessages::class)->record(new SentMessage($id, MessageKind::OrderTaken, null, null, null, MessageChannel::Email, null, SendStatus::Sent, $at->toDateTimeImmutable()));

    return $id;
}

it('removes what is older than twelve months from the three tables, keeps the rest, and is the system\'s alone', function () {
    $customer = Fx::customer();
    // Registering sent the customer's email-verification link, and left its own row.
    DB::table('ops.sent_messages')->delete();
    $old = CarbonImmutable::now()->subMonthsNoOverflow(12)->subMinute();
    $kept = CarbonImmutable::now()->subMonthsNoOverflow(12)->addMinute();

    Ox::customerEntry($customer, 'sa', $old);
    $keptEntry = Ox::customerEntry($customer, 'sa', $kept);
    pruneSent($old);
    $keptSent = pruneSent($kept);
    DB::table('ops.handled_events')->insert([
        ['event_id' => (string) Str::uuid(), 'listener' => 'old', 'handled_at' => $old->utc()->format('Y-m-d H:i:s.uP')],
        ['event_id' => (string) Str::uuid(), 'listener' => 'kept', 'handled_at' => $kept->utc()->format('Y-m-d H:i:s.uP')],
    ]);

    Fx::actAsStaff(Fx::staffWith(['ops.report.view'], ['sa']));
    expect(fn () => app(PruneOpsLogHandler::class)->handle(new PruneOpsLog))->toThrow(Unauthorized::class);

    expect(Fx::asSystem(fn (): array => app(PruneOpsLogHandler::class)->handle(new PruneOpsLog)))
        ->toBe(['inbox_entries' => 1, 'sent_messages' => 1, 'handled_events' => 1])
        ->and(DB::table('ops.inbox_entries')->pluck('id')->all())->toBe([$keptEntry])
        ->and(DB::table('ops.sent_messages')->pluck('id')->all())->toBe([$keptSent])
        // Registering the customers above is itself an event Ops handles (step 7): its row is new and stays.
        ->and(DB::table('ops.handled_events')->whereNotLike('listener', 'Modules%')->pluck('listener')->all())->toBe(['kept']);
});

it('runs each night at 04:30 Riyadh time on one server', function () {
    $prune = collect(app(Schedule::class)->events())->filter(static fn ($event): bool => $event->description === PruneOpsLogJob::class)->values();
    $night = $prune->first();
    assert($night instanceof ScheduledEvent);

    expect($prune)->toHaveCount(1)
        ->and([$night->expression, $night->timezone, $night->onOneServer])->toBe(['30 4 * * *', 'Asia/Riyadh', true]);
});

it('runs a listener\'s work once per event, however often the event arrives', function () {
    $events = app(HandledEvents::class);
    $eventId = (string) Str::uuid();
    $runs = 0;

    expect($events->once($eventId, 'C1', function () use (&$runs): void {
        $runs++;
    }))->toBeTrue()
        ->and($events->once($eventId, 'C1', function () use (&$runs): void {
            $runs++;
        }))->toBeFalse()
        // Another listener of the same event runs its own work (OrderPlaced: C1 and S1).
        ->and($events->once($eventId, 'S1', function () use (&$runs): void {
            $runs++;
        }))->toBeTrue()
        ->and($runs)->toBe(2);
});

it('keeps no record of an event whose work failed, so it runs again', function () {
    $events = app(HandledEvents::class);
    $eventId = (string) Str::uuid();

    expect(fn () => $events->once($eventId, 'C1', fn () => throw new RuntimeException('provider down')))->toThrow(RuntimeException::class)
        ->and(DB::table('ops.handled_events')->where('event_id', $eventId)->exists())->toBeFalse();
});
