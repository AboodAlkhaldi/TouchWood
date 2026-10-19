<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sales\Public\Events\OrderPlaced;
use Shared\Infrastructure\Outbox\Outbox;
use Shared\Infrastructure\Outbox\OutboxRelay;
use Shared\Infrastructure\Outbox\ProcessedEvents;

/*
| The transactional outbox and the idempotent consumer (handoff §4.5; platform.md §5.6; sales.md §5, §8
| "Events and the outbox"): an event written in its change's transaction, dispatched at least once and
| in order, retried further apart and never dropped, acted on once by each consumer.
*/

uses(RefreshDatabase::class);

function outboxEvent(?string $eventId = null): OrderPlaced
{
    return new OrderPlaced($eventId ?? (string) Str::uuid(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), CarbonImmutable::parse('2026-10-20 09:30:15.123+00:00'));
}

function outboxRelay(): void
{
    app()->call([new OutboxRelay, 'handle']);
}

/**
 * @return array<string, mixed>
 */
function outboxRow(string $eventId): array
{
    return (array) (DB::table('public.outbox_messages')->where('event_id', $eventId)->first() ?? throw new RuntimeException('No such message.'));
}

it('loses an event with a change that rolls back, and keeps it with one that commits', function () {
    $kept = outboxEvent();
    $lost = outboxEvent();

    DB::transaction(fn () => app(Outbox::class)->record($kept));

    try {
        DB::transaction(function () use ($lost): void {
            app(Outbox::class)->record($lost);

            throw new RuntimeException('The change failed.');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('public.outbox_messages')->pluck('event_id')->all())->toBe([$kept->eventId])
        ->and(json_decode((string) outboxRow($kept->eventId)['payload'], true))->toBe([
            'eventId' => $kept->eventId, 'orderId' => $kept->orderId, 'storeId' => $kept->storeId, 'customerId' => $kept->customerId,
            'occurredAt' => '2026-10-20T09:30:15.123+00:00',
        ]);
});

it('keeps one message for one event written twice', function () {
    $event = outboxEvent();

    DB::transaction(function () use ($event): void {
        app(Outbox::class)->record($event);
        app(Outbox::class)->record($event);
    });

    expect(DB::table('public.outbox_messages')->count())->toBe(1);
});

it('dispatches the waiting events in the order written, as they were, and marks them', function () {
    Event::fake([OrderPlaced::class]);
    $first = outboxEvent();
    $second = outboxEvent();
    DB::transaction(function () use ($first, $second): void {
        app(Outbox::class)->record($first);
        app(Outbox::class)->record($second);
    });

    outboxRelay();

    $seen = [];
    Event::assertDispatched(OrderPlaced::class, function (OrderPlaced $event) use (&$seen): bool {
        $seen[] = $event;

        return true;
    });

    expect(array_map(static fn (OrderPlaced $event): string => $event->eventId, $seen))->toBe([$first->eventId, $second->eventId])
        ->and([$seen[0]->orderId, $seen[0]->storeId, $seen[0]->customerId])->toBe([$first->orderId, $first->storeId, $first->customerId])
        ->and($seen[0]->occurredAt->format('Y-m-d H:i:s.v P'))->toBe('2026-10-20 09:30:15.123 +00:00')
        ->and(DB::table('public.outbox_messages')->whereNull('dispatched_at')->count())->toBe(0);

    outboxRelay();
    Event::assertDispatchedTimes(OrderPlaced::class, 2);
});

it('leaves a message whose time has not come', function () {
    Event::fake([OrderPlaced::class]);
    $event = outboxEvent();
    DB::transaction(fn () => app(Outbox::class)->record($event));
    DB::table('public.outbox_messages')->update(['available_at' => CarbonImmutable::now()->addMinute()]);

    outboxRelay();

    Event::assertNotDispatched(OrderPlaced::class);
});

it('keeps a failing message, waits longer each time up to an hour, and logs it from the tenth attempt', function () {
    $log = Log::spy();
    $event = outboxEvent();
    DB::transaction(fn () => app(Outbox::class)->record($event));
    Event::listen(OrderPlaced::class, static fn () => throw new RuntimeException('The listener is away.'));
    CarbonImmutable::setTestNow('2026-10-20 10:00:00');

    outboxRelay();
    $row = outboxRow($event->eventId);

    expect([$row['attempts'], $row['dispatched_at']])->toBe([1, null])
        ->and((string) $row['last_error'])->toContain('The listener is away.')
        ->and(CarbonImmutable::parse((string) $row['available_at'])->equalTo(CarbonImmutable::parse('2026-10-20 10:01:00')))->toBeTrue()
        ->and(array_map(OutboxRelay::wait(...), [1, 2, 3, 4, 5, 6, 7, 8, 20]))->toBe([1, 2, 4, 8, 16, 32, 60, 60, 60]);

    $log->shouldNotHaveReceived('error');

    DB::table('public.outbox_messages')->update(['attempts' => 9, 'available_at' => CarbonImmutable::now()]);
    outboxRelay();

    expect(outboxRow($event->eventId)['attempts'])->toBe(10)
        ->and(DB::table('public.outbox_messages')->count())->toBe(1);
    $log->shouldHaveReceived('error')->once();
});

it('prunes dispatched messages after 30 days, and never one still waiting', function () {
    Event::fake([OrderPlaced::class]);
    $old = outboxEvent();
    $recent = outboxEvent();
    $waiting = outboxEvent();
    DB::transaction(function () use ($old, $recent, $waiting): void {
        foreach ([$old, $recent, $waiting] as $event) {
            app(Outbox::class)->record($event);
        }
    });
    DB::table('public.outbox_messages')->where('event_id', $old->eventId)->update(['dispatched_at' => CarbonImmutable::now()->subDays(31)]);
    DB::table('public.outbox_messages')->where('event_id', $recent->eventId)->update(['dispatched_at' => CarbonImmutable::now()->subDays(29)]);
    DB::table('public.outbox_messages')->where('event_id', $waiting->eventId)->update(['available_at' => CarbonImmutable::now()->addHour(), 'created_at' => CarbonImmutable::now()->subDays(40)]);

    outboxRelay();

    expect(DB::table('public.outbox_messages')->pluck('event_id')->sort()->values()->all())->toEqualCanonicalizing([$recent->eventId, $waiting->eventId]);
});

it('acts on an event once per listener, however often it arrives', function () {
    $runs = 0;
    $eventId = (string) Str::uuid();
    $work = static function () use (&$runs): void {
        $runs++;
    };

    $first = app(ProcessedEvents::class)->once(strtoupper($eventId), 'Feedback.Flip', $work);
    $again = app(ProcessedEvents::class)->once($eventId, 'Feedback.Flip', $work);
    $other = app(ProcessedEvents::class)->once($eventId, 'Loyalty.Earn', $work);

    expect([$first, $again, $other, $runs])->toBe([true, false, true, 2]);
});

it('forgets the mark when the work fails, so a later arrival does it', function () {
    $eventId = (string) Str::uuid();

    expect(fn () => app(ProcessedEvents::class)->once($eventId, 'Sales.Flag', static fn () => throw new RuntimeException('Not now.')))->toThrow(RuntimeException::class)
        ->and(app(ProcessedEvents::class)->once($eventId, 'Sales.Flag', static function (): void {}))->toBeTrue();
});

it('relays every minute from one server, as a queued job', function () {
    $relay = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event->description === OutboxRelay::class);

    expect($relay)->not->toBeNull()
        ->and($relay?->expression)->toBe('* * * * *')
        ->and($relay?->onOneServer)->toBeTrue();
});
