<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Inventory\Public\Dto\ProviderStockResult;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnection;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnectionHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\UnwireStore\UnwireStore;
use Modules\Sync\Application\Command\UnwireStore\UnwireStoreHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Infrastructure\Queue\PruneSyncLogJob;
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\call;
use function Pest\Laravel\seed;

/*
| Sync's own pass (step 6): what the three reviews of the whole module found, each proven - the
| provider's notice written one way and checked before it counts; a waiting notice's codes capped; a
| read that crashes keeps what it wrote, is taken back once dead, and its record stays exact; one store
| never touches another; unwiring keeps what the provider wrote.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-18 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
    RateLimiter::clear('sync-notify:'.Fx::storeId('sa'));
    RateLimiter::clear('sync-notify-ip:127.0.0.1');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The provider's notice, signed - its signature written as given when told.
 *
 * @return TestResponse<Response>
 */
function syncPassNotice(string $body, ?callable $written = null): TestResponse
{
    $timestamp = (string) CarbonImmutable::now()->getTimestamp();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'the-secret');

    return call('POST', '/sync/notify/'.Fx::storeId('sa'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TOUCHWOOD_TIMESTAMP' => $timestamp,
        'HTTP_X_TOUCHWOOD_SIGNATURE' => $written === null ? $signature : $written($signature),
    ], $body);
}

/** The store's waiting read, run as its job runs it. */
function syncPassRun(): ?int
{
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('queued_at')->value('id');

    return Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));
}

/**
 * A ready product of the sizes, switched on in KSA, each size's code.
 *
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>, codes: list<string>}
 */
function syncPassProduct(array $sizes = ['60 cm']): array
{
    $ready = Px::ready($sizes);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));

    return [...$ready, 'codes' => array_map(static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code'), $ready['variants'])];
}

describe('the provider\'s notice', function () {
    it('takes a signature in another case or with spaces as the same notice: queued once', function () {
        DB::table('sync.reads')->delete();
        Queue::fake();
        $body = '{"codes": ["123"]}';

        syncPassNotice($body)->assertStatus(202);
        syncPassNotice($body, static fn (string $signature): string => strtoupper($signature))->assertStatus(202);
        syncPassNotice($body, static fn (string $signature): string => ' '.$signature.' ')->assertStatus(202);

        Queue::assertPushed(ReadStoreJob::class, 1);
    });

    it('refuses a signature that is not 64 hexadecimal characters, and a code with a control character', function () {
        syncPassNotice('{"codes": ["1"]}', static fn (string $signature): string => $signature.'00')->assertStatus(401);
        syncPassNotice('{"codes": ["1\u00002"]}')->assertStatus(422);
        syncPassNotice('{"codes": ["1\u00072"]}')->assertStatus(422);
    });

    it('refuses a notice\'s body every time it comes - never taken as seen', function () {
        $body = '{"codes": []}';

        syncPassNotice($body)->assertStatus(422);
        syncPassNotice($body)->assertStatus(422);
    });

    it('turns one address away past 600 calls a minute, whatever store it names', function () {
        foreach (range(1, 600) as $call) {
            RateLimiter::hit('sync-notify-ip:127.0.0.1', 60);
        }

        syncPassNotice('{"codes": ["1"]}')->assertStatus(429)->assertHeader('Retry-After');
    });

    it('holds 5,000 codes at most in a waiting notice\'s read; past them, one full read of the store (owner, 2026-10-18)', function () {
        DB::table('sync.reads')->delete();
        $store = StoreId::fromString(Fx::storeId('sa'));

        foreach (range(0, 4) as $notice) {
            Fx::asSystem(fn () => app(Reads::class)->queue($store, ReadKind::Notified, array_map(static fn (int $n): string => (string) ($notice * 1000 + $n), range(1, 1000))));
        }

        expect(DB::table('sync.reads')->pluck('kind')->all())->toBe(['NOTIFIED']);

        Fx::asSystem(fn () => app(Reads::class)->queue($store, ReadKind::Notified, ['99999']));

        expect(DB::table('sync.reads')->orderBy('kind')->pluck('kind')->all())->toBe(['NOTIFIED', 'NOW'])
            ->and(DB::selectOne("SELECT jsonb_array_length(codes) AS n FROM sync.reads WHERE kind = 'NOTIFIED'")->n)->toBe(5000);
    });
});

describe('a read that crashes, or is picked up twice', function () {
    it('keeps the batches it wrote, starts the store\'s next read, and is tried again - its record exact, its counts from what it wrote', function () {
        ['variants' => [$first, $second], 'codes' => [$a, $b]] = syncPassProduct(['60 cm', '80 cm']);
        // 499 codes we do not have and the first size fill the first batch of 500; the second size is the next.
        FakeProvider::bound()->values = [
            ...array_map(static fn (int $n): ProviderValue => new ProviderValue('X'.$n, '1', 1), range(1, 499)),
            new ProviderValue($a, '120.50', null),
            new ProviderValue($b, '80', 3),
        ];
        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Hourly));
        Queue::fake();
        $real = app(ProviderStock::class);
        app()->instance(ProviderStock::class, new class($real) implements ProviderStock
        {
            public function __construct(private ProviderStock $real) {}

            public function apply(StoreId $store, array $stock, string $readRef): ProviderStockResult
            {
                throw new RuntimeException('The worker went away.');
            }

            public function wired(StoreId $store): void
            {
                $this->real->wired($store);
            }

            public function stopsWhenUnwired(StoreId $store): int
            {
                return $this->real->stopsWhenUnwired($store);
            }

            public function unwired(StoreId $store): int
            {
                return $this->real->unwired($store);
            }
        });
        $read = (string) DB::table('sync.reads')->where('kind', 'NOW')->value('id');

        expect(fn () => Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read))))->toThrow(RuntimeException::class);

        expect(Rx::retail('sa', $first)?->amount_minor)->toBe(12050)
            ->and(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('QUEUED');
        // The store's next read started; never this one again before its own retry's wait.
        Queue::assertPushed(ReadStoreJob::class, fn (ReadStoreJob $job): bool => $job->readId === (string) DB::table('sync.reads')->where('kind', 'HOURLY')->value('id'));
        Queue::assertNotPushed(ReadStoreJob::class, fn (ReadStoreJob $job): bool => $job->readId === $read);

        // Tried again: everything reported once, not twice; a price set by the first attempt still counted.
        app()->instance(ProviderStock::class, $real);
        Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

        expect(DB::table('sync.read_reports')->where('read_id', $read)->where('kind', 'UNKNOWN_CODE')->count())->toBe(499)
            ->and(DB::table('sync.reads')->where('id', $read)->first(['state', 'prices_changed', 'stock_changed', 'skipped']))->toMatchObject(['state' => 'DONE', 'prices_changed' => 2, 'stock_changed' => 1, 'skipped' => 499]);
    });

    it('waits for a read running elsewhere until it is taken as dead - and not for another read of the store', function () {
        $read = (string) DB::table('sync.reads')->value('id');
        DB::table('sync.reads')->where('id', $read)->update(['state' => 'RUNNING', 'started_at' => now()->subMinutes(10)]);

        expect(Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read))))->toBe(20 * 60 + 1);

        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Hourly));
        $hourly = (string) DB::table('sync.reads')->where('kind', 'HOURLY')->value('id');

        expect(Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($hourly))))->toBeNull()
            ->and(DB::table('sync.reads')->where('id', $hourly)->value('state'))->toBe('QUEUED');
    });

    it('states its tries, its waits and its time - and the prune its own', function () {
        $job = new ReadStoreJob('r');
        $prune = new PruneSyncLogJob;

        expect([$job->tries, $job->backoff, $job->timeout])->toBe([4, [60, 300, 900], 1200])
            ->and([$prune->tries, $prune->timeout])->toBe([3, 900]);
    });
});

describe('what the provider sends that we cannot keep as it is', function () {
    it('reports a code with a control character written with "?", and keeps 100 characters of a value', function () {
        ['variants' => [$size], 'codes' => [$code]] = syncPassProduct();
        FakeProvider::bound()->values = [new ProviderValue("AB\u{0001}C", '5', 1), new ProviderValue($code, str_repeat('9', 300), null)];

        syncPassRun();

        expect(DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->value('code'))->toBe('AB?C')
            ->and(mb_strlen((string) json_decode((string) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->where('variant_id', $size)->value('detail'), true)['price']))->toBe(100);
    });
});

describe('one store never touches another (§8.9)', function () {
    it('leaves another wired store as it is when a store\'s read fails, and its report and reads', function () {
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('ae'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
        DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('ae'), 'kind' => 'UNKNOWN_CODE', 'code' => '9', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);
        $before = DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->first(['state', 'last_error', 'last_read_at']);
        $read = (string) DB::table('sync.reads')->where('store_id', Fx::storeId('sa'))->value('id');
        FakeProvider::bound()->failure = 'Timed out';
        DB::table('sync.reads')->where('id', $read)->update(['state' => 'RUNNING', 'started_at' => now()]);

        Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'Timed out'));

        expect(DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->first(['state', 'last_error', 'last_read_at']))->toEqual($before)
            ->and(DB::table('sync.report_entries')->where('store_id', Fx::storeId('ae'))->whereNull('closed_at')->count())->toBe(1)
            ->and(DB::table('sync.report_entries')->where('store_id', Fx::storeId('ae'))->where('kind', 'READ_FAILED')->count())->toBe(0)
            ->and(DB::table('sync.reads')->where('store_id', Fx::storeId('ae'))->value('state'))->toBe('QUEUED');
    });
});

describe('unwiring (§1.8, §8.8)', function () {
    it('keeps what the provider wrote, and gives the panel its prices back at once', function () {
        ['variants' => [$size], 'codes' => [$code]] = syncPassProduct();
        FakeProvider::bound()->values = [new ProviderValue($code, '120.50', 7)];
        syncPassRun();

        app(UnwireStoreHandler::class)->handle(new UnwireStore(Fx::storeId('sa')));

        expect(Rx::retail('sa', $size)?->amount_minor)->toBe(12050)
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(7);

        Rx::set('sa', [$size => '10']);

        expect(Rx::retail('sa', $size)?->amount_minor)->toBe(1000);
    });

    it('refuses a store that is not one - changing or unwiring it - as an invalid store', function () {
        $none = '01jbzzzzzzzzzzzzzzzzzzzzzz';

        expect(fn () => app(ChangeConnectionHandler::class)->handle(new ChangeConnection($none, 'ODOO', 'https://odoo.test/api')))->toThrow(InvalidSyncInput::class)
            ->and(fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore($none)))->toThrow(InvalidSyncInput::class);
    });
});
