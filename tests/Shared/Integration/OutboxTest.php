<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Platform\Infrastructure\Queue\RelayOutboxJob;
use Modules\Sales\Public\Events\OrderCancelled;
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

final readonly class OutboxTestEvent
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public CarbonImmutable $at,
        public string $note = 'none',
    ) {}
}

function outboxEvent(?string $eventId = null): OrderPlaced
{
    return new OrderPlaced($eventId ?? (string) Str::uuid(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), CarbonImmutable::parse('2026-10-20 09:30:15.123+00:00'));
}

function outboxRelay(): void
{
    app(OutboxRelay::class)->run();
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
            'occurredAt' => '2026-10-20T09:30:15.123000+00:00',
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

it('relays every minute from one server, as a queued job never run twice at once', function () {
    Queue::fake();
    $relay = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event->description === RelayOutboxJob::class);

    expect($relay)->not->toBeNull()
        ->and($relay?->expression)->toBe('* * * * *')
        ->and($relay?->onOneServer)->toBeTrue()
        ->and(new RelayOutboxJob)->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new RelayOutboxJob)->timeout)->toBeLessThan(OutboxRelay::LEASE);

    $relay?->run(app());

    Queue::assertPushed(RelayOutboxJob::class, 1);
});

it('holds back the later events of an order behind its failed one, and those of no other order', function () {
    $failing = outboxEvent();
    $sameOrder = new OrderCancelled((string) Str::uuid(), $failing->orderId, $failing->storeId, $failing->customerId, CarbonImmutable::now());
    $otherOrder = outboxEvent();
    DB::transaction(function () use ($failing, $sameOrder, $otherOrder): void {
        app(Outbox::class)->record($failing);
        app(Outbox::class)->record($sameOrder);
        app(Outbox::class)->record($otherOrder);
    });
    $seen = [];
    Event::listen(OrderPlaced::class, static function (OrderPlaced $event) use ($failing, &$seen): void {
        if ($event->eventId === $failing->eventId) {
            throw new RuntimeException('Not now.');
        }

        $seen[] = $event->eventId;
    });
    Event::listen(OrderCancelled::class, static function (OrderCancelled $event) use (&$seen): void {
        $seen[] = $event->eventId;
    });

    outboxRelay();
    DB::table('public.outbox_messages')->where('event_id', $failing->eventId)->update(['available_at' => CarbonImmutable::now()->subSecond()]);
    outboxRelay();

    expect($seen)->toBe([$otherOrder->eventId])
        ->and(DB::table('public.outbox_messages')->whereNull('dispatched_at')->pluck('event_id')->sort()->values()->all())->toEqualCanonicalizing([$failing->eventId, $sameOrder->eventId])
        ->and(outboxRow($failing->eventId)['attempts'])->toBe(2);
});

it('counts the attempt when it claims, so a relay killed mid-dispatch still counts it', function () {
    $event = outboxEvent();
    DB::transaction(fn () => app(Outbox::class)->record($event));

    $claimed = app(OutboxRelay::class)->claim(DB::connection(), CarbonImmutable::now());

    expect($claimed)->toHaveCount(1)
        ->and(outboxRow($event->eventId)['attempts'])->toBe(1);
});

it('refuses an event id that is not a UUID before the database could end the transaction', function () {
    $event = new OrderPlaced('not-a-uuid', 'o', 's', 'c', CarbonImmutable::now());

    DB::transaction(function () use ($event): void {
        expect(fn () => app(Outbox::class)->record($event))->toThrow(LogicException::class, 'UUID');
        // The transaction is still good.
        expect(DB::table('public.outbox_messages')->count())->toBe(0);
    });
});

it('rebuilds an event as it was: a parameter with a default kept, a Carbon moment back into Carbon', function () {
    Event::fake([OutboxTestEvent::class]);
    DB::transaction(fn () => app(Outbox::class)->record(new OutboxTestEvent((string) Str::uuid(), 'o1', CarbonImmutable::parse('2026-10-20 08:00:00.654321+03:00'))));
    // Written by an older shape of the event, before its note existed.
    DB::table('public.outbox_messages')->update(['payload' => DB::raw("payload - 'note'")]);

    outboxRelay();

    Event::assertDispatched(OutboxTestEvent::class, static fn (OutboxTestEvent $event): bool => $event->note === 'none'
        && $event->at::class === CarbonImmutable::class
        && $event->at->format('Y-m-d H:i:s.u P') === '2026-10-20 08:00:00.654321 +03:00');
});
