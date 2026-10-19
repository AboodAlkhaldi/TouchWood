<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use Modules\Sales\Public\Events\OrderPlaced;
use Shared\Infrastructure\Outbox\Outbox;
use Shared\Infrastructure\Outbox\OutboxRelay;

/*
| Two relays at once never take one row (sales.md §8 "Events and the outbox"): the first claims its rows
| under FOR UPDATE SKIP LOCKED; the second, at that moment, skips them rather than waiting, and once the
| claim is committed finds them leased to later.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database
| before and after itself; skipped under --parallel.
*/

function outboxRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (outboxRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    config(['database.connections.outbox_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (outboxRaceInParallel()) {
        return;
    }

    DB::connection('outbox_race')->disconnect();
    Artisan::call('migrate:fresh');
});

it('never lets two relays take one message', function () {
    foreach (range(1, 3) as $n) {
        DB::table('public.outbox_messages')->insert([
            'event_id' => (string) Str::uuid(), 'type' => 'X', 'payload' => '{}', 'created_at' => CarbonImmutable::now(),
            'available_at' => CarbonImmutable::now()->subSecond(), 'attempts' => 0,
        ]);
    }

    $first = DB::connection();
    $second = DB::connection('outbox_race');
    $ids = static fn (array $rows): array => array_map(static fn ($row): int => (int) $row->id, $rows);

    $first->beginTransaction();

    try {
        // The first relay claims, its rows locked until its claim is committed.
        $mine = $ids(app(OutboxRelay::class)->claim($first, CarbonImmutable::now()));
        // The second relay, meanwhile, skips them rather than waiting: nothing is left for it.
        $theirs = $ids(app(OutboxRelay::class)->claim($second, CarbonImmutable::now()));

        expect($mine)->toHaveCount(3)
            ->and($theirs)->toBe([]);

        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    // Committed, the claim is a lease: the rows stay the first relay's until it runs out.
    expect($ids(app(OutboxRelay::class)->claim($second, CarbonImmutable::now())))->toBe([])
        ->and($ids(app(OutboxRelay::class)->claim($second, CarbonImmutable::now()->addSeconds(OutboxRelay::LEASE + 1))))->toBe($mine);
})->skip(fn (): bool => outboxRaceInParallel(), 'Empties its database: run serially.');

it('refuses an event written outside any transaction: it would tell of a change that may not stay', function () {
    $event = new OrderPlaced((string) Str::uuid(), 'o', 's', 'c', CarbonImmutable::now());

    expect(fn () => app(Outbox::class)->record($event))->toThrow(LogicException::class, 'inside the transaction')
        ->and(DB::table('public.outbox_messages')->count())->toBe(0);
})->skip(fn (): bool => outboxRaceInParallel(), 'Empties its database: run serially.');
