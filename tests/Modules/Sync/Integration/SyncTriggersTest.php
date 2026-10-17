<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Sync\Application\Command\QueueScheduledReads\QueueScheduledReads;
use Modules\Sync\Application\Command\QueueScheduledReads\QueueScheduledReadsHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Infrastructure\Queue\QueueScheduledReadsJob;
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\call;
use function Pest\Laravel\seed;

/*
| What starts a read (sync.md §1.3): the provider's signed notice - answered at once, refused when not
| its own, limited per store; the hour's and the night's reads of every wired store; a corrected code
| read again in every wired store.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-17 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
    // Wiring's own first read, set aside: these tests count what the triggers queue.
    DB::table('sync.reads')->delete();
    Queue::fake();
    RateLimiter::clear('sync-notify:'.Fx::storeId('sa'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The provider's call, signed as it signs it unless told otherwise.
 *
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function syncNotice(string $body, ?string $secret = 'the-secret', ?int $at = null, array $headers = [], ?string $store = null): TestResponse
{
    $timestamp = (string) ($at ?? CarbonImmutable::now()->getTimestamp());
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TOUCHWOOD_TIMESTAMP' => $timestamp];

    if ($secret !== null) {
        $server['HTTP_X_TOUCHWOOD_SIGNATURE'] = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return call('POST', '/sync/notify/'.($store ?? Fx::storeId('sa')), [], [], [], $server, $body);
}

/**
 * @return list<array{kind: string, codes: list<string>|null}>
 */
function syncTriggered(): array
{
    return array_values(array_map(
        static fn (stdClass $read): array => ['kind' => (string) $read->kind, 'codes' => $read->codes === null ? null : json_decode((string) $read->codes, true, flags: JSON_THROW_ON_ERROR)],
        DB::table('sync.reads')->orderBy('queued_at')->orderBy('id')->get(['kind', 'codes'])->all(),
    ));
}

describe('the provider\'s notice', function () {
    it('answers a signed notice at once and queues a read of its codes - trimmed, once each - calling nothing', function () {
        syncNotice('{"codes": ["123", " 456 ", "123"]}')->assertStatus(202)->assertNoContent(202);

        expect(syncTriggered())->toBe([['kind' => 'NOTIFIED', 'codes' => ['123', '456']]])
            ->and(FakeProvider::bound()->reads)->toBe([]);
        Queue::assertPushed(ReadStoreJob::class, 1);
    });

    it('refuses with no body a notice not its provider\'s: a wrong secret, no signature, a time over 5 minutes off', function (Closure $send) {
        $send()->assertStatus(401)->assertContent('');

        expect(syncTriggered())->toBe([]);
    })->with([
        'a wrong secret' => [fn () => syncNotice('{"codes": ["1"]}', 'another')],
        'no signature' => [fn () => syncNotice('{"codes": ["1"]}', null)],
        'a signature for another body' => [fn () => syncNotice('{"codes": ["1"]}', headers: ['X-TouchWood-Signature' => hash_hmac('sha256', CarbonImmutable::now()->getTimestamp().'.{"codes": ["2"]}', 'the-secret')])],
        'six minutes old' => [fn () => syncNotice('{"codes": ["1"]}', at: CarbonImmutable::now()->subMinutes(6)->getTimestamp())],
        'six minutes ahead' => [fn () => syncNotice('{"codes": ["1"]}', at: CarbonImmutable::now()->addMinutes(6)->getTimestamp())],
        'a time that is not one' => [fn () => syncNotice('{"codes": ["1"]}', headers: ['X-TouchWood-Timestamp' => 'soon'])],
        'a store not wired' => [fn () => syncNotice('{"codes": ["1"]}', store: Fx::storeId('ae'))],
        'a store that is not one' => [fn () => syncNotice('{"codes": ["1"]}', store: 'nope')],
    ]);

    it('takes a time up to 5 minutes off', function () {
        syncNotice('{"codes": ["1"]}', at: CarbonImmutable::now()->subMinutes(5)->getTimestamp())->assertStatus(202);
        syncNotice('{"codes": ["2"]}', at: CarbonImmutable::now()->addMinutes(5)->getTimestamp())->assertStatus(202);

        expect(syncTriggered())->toHaveCount(2);
    });

    it('refuses a signed body that is not a list of 1 to 1,000 codes', function (string $body) {
        syncNotice($body)->assertStatus(422);

        expect(syncTriggered())->toBe([]);
    })->with([
        'not JSON' => ['codes: 1'],
        'no list' => ['{"codes": "123"}'],
        'an empty list' => ['{"codes": []}'],
        'a list of objects' => ['{"codes": {"a": "1"}}'],
        'a code that is not text' => ['{"codes": [123]}'],
        'an empty code' => ['{"codes": ["  "]}'],
        'a code too long' => ['{"codes": ["'.str_repeat('1', 256).'"]}'],
        'more than 1,000' => ['{"codes": ['.implode(',', array_map(static fn (int $n): string => '"'.$n.'"', range(1, 1001))).']}'],
    ]);

    it('takes 1,000 codes', function () {
        syncNotice('{"codes": ['.implode(',', array_map(static fn (int $n): string => '"'.$n.'"', range(1, 1000))).']}')->assertStatus(202);
    });

    it('turns away more than 60 calls a minute for a store, signed or not, until the minute passes', function () {
        for ($call = 1; $call <= 60; $call++) {
            syncNotice('{"codes": ["'.$call.'"]}', $call % 2 === 0 ? 'the-secret' : 'wrong');
        }

        syncNotice('{"codes": ["61"]}')->assertStatus(429);

        expect(DB::table('sync.reads')->count())->toBe(30);

        // The limiter counts by the app's clock.
        CarbonImmutable::setTestNow('2026-10-17 10:01:01+00');
        Carbon::setTestNow('2026-10-17 10:01:01+00');

        syncNotice('{"codes": ["62"]}')->assertStatus(202);
    });
});

describe('the hour\'s and the night\'s reads', function () {
    it('queues the kind for every wired store, a switched-off one too, and none for a store not wired', function () {
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('ae'), 'ODOO', 'https://odoo2.test/api', 'key', 'secret'));
        DB::table('sync.reads')->delete();
        DB::table('platform.stores')->where('id', Fx::storeId('ae'))->update(['is_active' => false]);

        $read = Fx::asSystem(fn (): int => app(QueueScheduledReadsHandler::class)->handle(new QueueScheduledReads('HOURLY')));

        expect($read)->toBe(2)
            ->and(DB::table('sync.reads')->where('kind', 'HOURLY')->pluck('store_id')->sort()->values()->all())->toBe(collect([Fx::storeId('sa'), Fx::storeId('ae')])->sort()->values()->all());
    });

    it('never piles up: a store whose read of the kind still waits gets no second', function () {
        Fx::asSystem(fn () => app(QueueScheduledReadsHandler::class)->handle(new QueueScheduledReads('NIGHTLY')));
        Fx::asSystem(fn () => app(QueueScheduledReadsHandler::class)->handle(new QueueScheduledReads('NIGHTLY')));

        expect(syncTriggered())->toBe([['kind' => 'NIGHTLY', 'codes' => null]]);
    });

    it('is the system\'s', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => app(QueueScheduledReadsHandler::class)->handle(new QueueScheduledReads('HOURLY')))->toThrow(Unauthorized::class);
    });

    it('is scheduled every hour, and every night at 02:00, on one server', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->description, QueueScheduledReadsJob::class))
            ->map(static fn ($event): string => $event->expression.($event->onOneServer ? ' one' : ''))
            ->sort()->values()->all();

        expect($events)->toBe(['0 * * * * one', '0 2 * * * one']);
    });
});

describe('a corrected code', function () {
    it('is read again at once, its new code, in every wired store', function () {
        $size = Px::ready()['variants'][0];

        Fx::asSystem(fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($size, '7300001')));

        expect(syncTriggered())->toBe([['kind' => 'CODE', 'codes' => ['7300001']]]);
    });
});
