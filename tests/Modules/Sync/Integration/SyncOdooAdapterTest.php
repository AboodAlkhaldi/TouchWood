<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\ValueObject\ConnectionState;
use Modules\Sync\Domain\ValueObject\ProviderKind;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Infrastructure\Provider\OdooAdapter;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| Odoo's adapter (sync.md §2.4, accepted 2026-10-19), against a faked HTTP API - no test reaches the
| network: each call's address, query and header; paging; what changed since the last read less 5 minutes,
| in UTC; codes 500 a call; `missing` left out; a code sent as a number taken as its digits; the pace of a
| second between calls; a 429 waited out once; every failure a short sentence without the body, the address
| or the key; a malformed answer. Then a whole read through it, and wiring a store through it.
*/

uses(RefreshDatabase::class);

const ODOO_BASE = 'https://odoo.test/touchwood/v1';
const ODOO_TOKEN = 'odoo-token-SECRET-1';

beforeEach(function () {
    // The waits are recorded, never slept; each moves the frozen clock on as a real one would.
    CarbonImmutable::setTestNow('2026-10-19 12:00:00+00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Sleep::fake(false);
});

function odooConnection(string $address = ODOO_BASE, string $store = 'store-1'): Connection
{
    return new Connection($store, ProviderKind::Odoo, $address, [], ODOO_TOKEN, 'notice-secret', ConnectionState::Ok, null, null, 'staff-1');
}

function odooAdapter(): OdooAdapter
{
    return app(OdooAdapter::class);
}

/**
 * An answer as §2.4 writes it.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  list<string>  $missing
 * @return array<string, mixed>
 */
function odooAnswer(array $items = [], ?string $next = null, array $missing = []): array
{
    return ['items' => $items, 'missing' => $missing, 'next' => $next];
}

/**
 * Every value a read yields, as [code, price, stock].
 *
 * @param  list<string>|null  $codes
 * @return list<array{0: string, 1: mixed, 2: mixed}>
 */
function odooRead(?array $codes = null, ?DateTimeImmutable $changedSince = null, ?Connection $connection = null): array
{
    $values = [];

    foreach (odooAdapter()->read($connection ?? odooConnection(), $codes, $changedSince) as $value) {
        $values[] = [$value->code, $value->price, $value->stock];
    }

    return $values;
}

/**
 * The addresses asked, in order, each from its path on (`/touchwood/v1/items?...`).
 *
 * @return list<string>
 */
function odooAsked(): array
{
    return array_values(Http::recorded()->map(fn (array $pair): string => substr($pair[0]->url(), strlen('https://odoo.test')))->all());
}

/**
 * The codes a named call asked for.
 *
 * @return list<string>
 */
function odooCodesAsked(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return explode(',', is_string($query['codes'] ?? null) ? $query['codes'] : '');
}

/** The failure's reason, or null when it did not fail. */
function odooFails(Closure $call): ?string
{
    try {
        $call();
    } catch (ConnectionFailed $failed) {
        return $failed->reason;
    }

    return null;
}

describe('the calls (§2.4)', function () {
    it('tests with GET /items?limit=1, the token as a bearer header, over HTTPS', function () {
        Http::fake(['odoo.test/*' => Http::response(odooAnswer([['code' => '123', 'price' => '1.00', 'available' => 1]]))]);

        odooAdapter()->test(odooConnection());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === ODOO_BASE.'/items?limit=1'
            && $request->header('Authorization') === ['Bearer '.ODOO_TOKEN]
            && $request->header('Accept') === ['application/json']);

        expect(odooAdapter()->ready())->toBeTrue()
            ->and(odooAdapter()->fields())->toBe([]);
    });

    it('waits 30 seconds at most a read\'s call and 10 the test\'s (§9.3 #21), and follows no redirect', function () {
        $options = [];
        Http::fake(['odoo.test/*' => function (Request $request, array $sent) use (&$options) {
            $options[] = [$sent['timeout'] ?? null, $sent['connect_timeout'] ?? null, $sent['allow_redirects'] ?? null];

            return Http::response(odooAnswer());
        }]);

        odooAdapter()->test(odooConnection());
        odooRead();
        odooRead(['123']);

        expect($options)->toBe([[10, 10, false], [30, 10, false], [30, 10, false]]);
    });

    it('takes an address written with a closing slash', function () {
        Http::fake(['odoo.test/*' => Http::response(odooAnswer())]);

        odooAdapter()->test(odooConnection(ODOO_BASE.'/'));

        expect(odooAsked())->toBe(['/touchwood/v1/items?limit=1']);
    });

    it('reads every item page by page, passing next as after until it is null', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push(odooAnswer([['code' => '101', 'price' => '10.00', 'available' => 1], ['code' => '102', 'price' => '20.00', 'available' => 2]], 'p2'))
            ->push(odooAnswer([['code' => '103', 'price' => '30.00', 'available' => 3]], 'p3'))
            ->push(odooAnswer([['code' => '104', 'price' => '40.00', 'available' => 0]]))]);

        expect(odooRead())->toBe([['101', '10.00', 1], ['102', '20.00', 2], ['103', '30.00', 3], ['104', '40.00', 0]])
            ->and(odooAsked())->toBe([
                '/touchwood/v1/items?limit=1000',
                '/touchwood/v1/items?limit=1000&after=p2',
                '/touchwood/v1/items?limit=1000&after=p3',
            ]);
    });

    it('reads what changed since the last read\'s start less 5 minutes, in UTC', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push(odooAnswer([['code' => '101', 'price' => '10.00', 'available' => 1]], 'n-1'))
            ->push(odooAnswer())]);

        // 15:00 in Riyadh is 12:00 UTC.
        odooRead(changedSince: new DateTimeImmutable('2026-10-19 15:00:00.654321+03:00'));

        expect(odooAsked())->toBe([
            '/touchwood/v1/items?changed_since=2026-10-19T11:55:00Z&limit=1000',
            '/touchwood/v1/items?changed_since=2026-10-19T11:55:00Z&limit=1000&after=n-1',
        ]);
    });

    it('asks named codes 500 a call, and leaves out those listed as missing', function () {
        $codes = array_map(static fn (int $n): string => (string) (10000 + $n), range(1, 1201));
        Http::fake(['odoo.test/*' => function (Request $request) {
            $asked = odooCodesAsked($request->url());

            // The first asked is there; the rest are not.
            return Http::response(odooAnswer([['code' => $asked[0], 'price' => '5.00', 'available' => 1]], null, array_slice($asked, 1)));
        }]);

        $values = odooRead($codes);
        $asked = array_map(odooCodesAsked(...), odooAsked());

        expect(array_map('count', $asked))->toBe([500, 500, 201])
            ->and(array_merge(...$asked))->toBe($codes)
            ->and(odooAsked()[0])->toStartWith('/touchwood/v1/items?codes=10001,10002,10003,')
            ->and(array_column($values, 0))->toBe(['10001', '10501', '11001']);
    });

    it('yields only the codes asked in a codes call: another the answer carries is left out, and its next not followed', function () {
        Http::fake(['odoo.test/*' => Http::response(odooAnswer([
            ['code' => '101', 'price' => '10.00', 'available' => 1],
            ['code' => '999', 'price' => '99.00', 'available' => 9],
            ['code' => 102, 'price' => '20.00', 'available' => 2],
        ], 'p2'))]);

        expect(odooRead(['101', '102']))->toBe([['101', '10.00', 1], ['102', '20.00', 2]])
            ->and(odooAsked())->toBe(['/touchwood/v1/items?codes=101,102']);
    });

    it('asks nothing for no codes', function () {
        Http::fake();

        expect(odooRead([]))->toBe([]);
        Http::assertNothingSent();
    });
});

describe('the answer', function () {
    it('takes a code sent as a number as its digits, passes price and stock on as they came, and ignores other fields', function () {
        Http::fake(['odoo.test/*' => Http::response('{"items": [{"code": 123, "price": "120.00", "available": 7, "name": "Oak"}, {"code": 12345678901234567890, "price": 85.5, "available": "2.5"}, {"code": "0123", "available": -1}], "missing": [], "next": null, "extra": true}')]);

        expect(odooRead())->toBe([['123', '120.00', 7], ['12345678901234567890', 85.5, '2.5'], ['0123', null, -1]]);
    });

    it('fails a malformed answer with a short reason', function (string $body, string $reason) {
        Http::fake(['odoo.test/*' => Http::response($body)]);

        expect(odooFails(fn () => odooRead()))->toBe("Odoo's answer is not as agreed: {$reason}.");
    })->with([
        'not JSON' => ['<html>Odoo</html>', 'it is not JSON'],
        'no items' => ['{"next": null}', 'its items are not a list'],
        'items not a list' => ['{"items": {"a": {"code": "1"}}}', 'its items are not a list'],
        'an item without a code' => ['{"items": [{"code": "1"}, {"price": "1.00"}]}', 'an item has no code'],
        'an empty code' => ['{"items": [{"code": " "}]}', 'an item has no code'],
        'an item not an object' => ['{"items": ["123"]}', 'an item has no code'],
        'next not text' => ['{"items": [], "next": {"page": 2}}', 'its next page is not text'],
    ]);

    it('fails pages that repeat rather than read for ever', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push(odooAnswer([], 'a'))
            ->push(odooAnswer([], 'b'))
            ->push(odooAnswer([], 'a'))]);

        expect(odooFails(fn () => odooRead()))->toBe("Odoo's answer is not as agreed: its pages repeat.");
    });
});

describe('the pace (§2.4; §9.1 #8)', function () {
    it('waits at least a second between a store\'s calls, a read after a test too, and never before the first', function () {
        $at = [];
        Http::fake(['odoo.test/*' => function (Request $request) use (&$at) {
            $at[] = Carbon::now()->getPreciseTimestamp(3);

            return Http::response(odooAnswer([], count($at) < 4 ? 'p'.count($at) : null));
        }]);

        odooAdapter()->test(odooConnection());
        odooRead();

        $gaps = array_map(static fn (float $then, float $now): float => $now - $then, array_slice($at, 0, -1), array_slice($at, 1));

        expect($at)->toHaveCount(4)
            ->and(array_filter($gaps, static fn (float $gap): bool => $gap < 1000.0))->toBe([]);
        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(fn (CarbonInterval $wait): bool => $wait->totalMilliseconds >= 1000, 3);
    });

    it('keeps each store to its own pace', function () {
        Http::fake(['odoo.test/*' => Http::response(odooAnswer())]);

        odooAdapter()->test(odooConnection(store: 'store-1'));
        odooAdapter()->test(odooConnection(store: 'store-2'));

        Sleep::assertNeverSlept();
    });
});

describe('too many calls (429)', function () {
    it('waits Retry-After, then asks once more', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push(odooAnswer([['code' => '101', 'price' => '10.00', 'available' => 1]]))]);

        expect(odooRead())->toBe([['101', '10.00', 1]]);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    });

    it('fails on a second 429', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '3'])
            ->push('', 429, ['Retry-After' => '3'])]);

        expect(odooFails(fn () => odooRead()))->toBe('Odoo answered 429 (too many calls) twice.');
        Http::assertSentCount(2);
    });

    it('waits 60 seconds at most - for a Retry-After past it, or none', function (?string $retryAfter) {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push('', 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])
            ->push(odooAnswer())]);

        odooRead();

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    })->with(['past it' => '600', 'none' => null, 'a date' => 'Wed, 21 Oct 2026 07:28:00 GMT']);

    it('fails the Super Admin\'s test at once: it runs in a web request, 10 seconds at most', function () {
        Http::fake(['odoo.test/*' => Http::response('', 429, ['Retry-After' => '5'])]);

        expect(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe('Odoo answered 429 (too many calls).');
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });
});

describe('failures: a short reason, never the body, the address or the key (§2.1)', function () {
    it('names what failed and its status code', function (int $status, string $reason) {
        Http::fake(['odoo.test/*' => Http::response(['error' => 'Body text with '.ODOO_TOKEN], $status)]);

        $read = odooFails(fn () => odooRead());
        $test = odooFails(fn () => odooAdapter()->test(odooConnection()));

        expect($read)->toBe($reason)
            ->and($test)->toBe($reason)
            ->and(str_contains((string) $read, 'Body text') || str_contains((string) $read, ODOO_TOKEN) || str_contains((string) $read, 'odoo.test'))->toBeFalse();
    })->with([
        '401' => [401, 'Odoo refused the token (401).'],
        '400' => [400, 'Odoo refused the request (400).'],
        '500' => [500, 'Odoo failed (500).'],
        '503' => [503, 'Odoo failed (503).'],
        '404' => [404, 'Odoo answered 404.'],
        'a redirect, never followed' => [302, 'Odoo answered 302.'],
    ]);

    it('says a timeout and a refused connection without the address', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received for '.ODOO_BASE.'/items?limit=1000')
            ->pushFailedConnection('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received for '.ODOO_BASE.'/items?limit=1')
            ->pushFailedConnection('cURL error 7: Failed to connect to odoo.test port 443 for '.ODOO_BASE.'/items?limit=1000')]);

        expect(odooFails(fn () => odooRead()))->toBe('Odoo did not answer within 30 seconds.')
            ->and(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe('Odoo did not answer within 10 seconds.')
            ->and(odooFails(fn () => odooRead()))->toBe('Odoo could not be reached.');
    });

    it('calls nothing at an address that is not HTTPS', function () {
        Http::fake();

        expect(odooFails(fn () => odooAdapter()->test(odooConnection('http://odoo.test/touchwood/v1'))))->toBe('Odoo\'s address must start with https://.');
        Http::assertNothingSent();
    });

    it('never shows the key when the connection is dumped', function () {
        expect(print_r(odooConnection(), true))->not->toContain(ODOO_TOKEN);
    });
});

describe('the address (§2.4)', function () {
    it('takes Odoo\'s address ending in /touchwood/v1, a closing slash dropped', function (string $given, string $kept) {
        expect(odooAdapter()->address($given))->toBe($kept);
    })->with([
        'as written' => ['https://odoo.test/touchwood/v1', 'https://odoo.test/touchwood/v1'],
        'a closing slash' => ['https://odoo.test/touchwood/v1/', 'https://odoo.test/touchwood/v1'],
        'a deeper path and a port' => ['https://odoo.test:8443/acme/touchwood/v1', 'https://odoo.test:8443/acme/touchwood/v1'],
    ]);

    it('refuses a query, a fragment or another path, each by its own rule', function (string $given, string $rule) {
        try {
            odooAdapter()->address($given);
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = [$error->attribute, $error->context()['rule'] ?? null];
        }

        expect($refused)->toBe(['address', $rule]);
    })->with([
        'a query' => ['https://odoo.test/touchwood/v1?db=tw', 'address_query'],
        'an empty query' => ['https://odoo.test/touchwood/v1?', 'address_query'],
        'a fragment' => ['https://odoo.test/touchwood/v1#x', 'address_query'],
        'no path' => ['https://odoo.test', 'address_path'],
        'another path' => ['https://odoo.test/api', 'address_path'],
        'its path not at the end' => ['https://odoo.test/touchwood/v1/items', 'address_path'],
        'another case' => ['https://odoo.test/TouchWood/v1', 'address_path'],
    ]);
});

describe('through Sync', function () {
    beforeEach(function () {
        seed(PlatformSeeder::class);
        seed(CatalogSeeder::class);
        Queue::fake();
        Fx::actAsStaff(Fx::staff(superAdmin: true));
    });

    it('wires a store once its test call works, and refuses a failed test, saving nothing', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push(['error' => 'bad token'], 401)
            ->push(odooAnswer())]);
        $wire = fn () => app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_BASE, ODOO_TOKEN, 'notice-secret'));

        expect(fn () => $wire())->toThrow(ConnectionFailed::class, 'Odoo refused the token (401).')
            ->and(DB::table('sync.connections')->count())->toBe(0);

        $wire();

        expect(DB::table('sync.connections')->where('store_id', Fx::storeId('sa'))->value('state'))->toBe('OK')
            ->and(DB::table('sync.reads')->where('store_id', Fx::storeId('sa'))->pluck('kind')->all())->toBe(['NOW'])
            ->and(odooAsked())->toBe(['/touchwood/v1/items?limit=1', '/touchwood/v1/items?limit=1']);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.ODOO_TOKEN]);
    });

    it('keeps Odoo\'s address with its closing slash dropped, and refuses another path before any call', function () {
        Http::fake(['odoo.test/*' => Http::response(odooAnswer())]);

        expect(fn () => app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', ODOO_TOKEN, 'notice-secret')))->toThrow(InvalidSyncInput::class);
        Http::assertNothingSent();

        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_BASE.'/', ODOO_TOKEN, 'notice-secret'));

        expect(DB::table('sync.connections')->value('address'))->toBe(ODOO_BASE)
            ->and(odooAsked())->toBe(['/touchwood/v1/items?limit=1']);
    });

    it('reads a store end to end: prices and stock written, a code sent twice reported as a bad value', function () {
        $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
        [$a, $b, $twice] = array_map(static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code'), $ready['variants']);

        Http::fake(['odoo.test/*' => Http::sequence()
            ->push(odooAnswer())
            ->push(odooAnswer([
                ['code' => $a, 'price' => '120.00', 'available' => 7],
                ['code' => $twice, 'price' => '50.00', 'available' => 1],
            ], 'p2'))
            ->push(odooAnswer([
                ['code' => (int) $b, 'price' => '85.50', 'available' => 4],
                ['code' => $twice, 'price' => '55.00', 'available' => 2],
                ['code' => '999999', 'price' => '1.00', 'available' => 1],
            ]))]);
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_BASE, ODOO_TOKEN, 'notice-secret'));
        $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');

        Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

        expect(Rx::retail('sa', $ready['variants'][0])?->amount_minor)->toBe(12000)
            ->and(Ix::stock('sa', $ready['variants'][0])?->in_stock)->toBe(7)
            ->and(Rx::retail('sa', $ready['variants'][1])?->amount_minor)->toBe(8550)
            ->and(Ix::stock('sa', $ready['variants'][1])?->in_stock)->toBe(4)
            ->and(Rx::retail('sa', $ready['variants'][2]))->toBeNull()
            ->and(DB::table('sync.report_entries')->whereNull('closed_at')->orderBy('kind')->get(['kind', 'code', 'variant_id'])->map(fn ($row): array => (array) $row)->all())->toBe([
                ['kind' => 'BAD_VALUE', 'code' => null, 'variant_id' => $ready['variants'][2]],
                ['kind' => 'UNKNOWN_CODE', 'code' => '999999', 'variant_id' => null],
            ])
            ->and(DB::table('sync.reads')->where('id', $read)->first(['state', 'prices_changed', 'stock_changed']))->toMatchObject(['state' => 'DONE', 'prices_changed' => 2, 'stock_changed' => 2])
            ->and(odooAsked())->toBe(['/touchwood/v1/items?limit=1', '/touchwood/v1/items?limit=1000', '/touchwood/v1/items?limit=1000&after=p2']);
    });

    it('stores the short reason when a read fails, never the body or the key', function () {
        Http::fake(['odoo.test/*' => Http::sequence()
            ->push(odooAnswer())
            ->push(['error' => 'Body text with '.ODOO_TOKEN], 503)]);
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_BASE, ODOO_TOKEN, 'notice-secret'));
        $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');

        expect(fn () => Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read))))->toThrow(ConnectionFailed::class, 'Odoo failed (503).');

        // The job's last attempt: as ReadStoreJob passes it.
        Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, (new ConnectionFailed('Odoo failed (503).'))->getMessage()));

        expect(DB::table('sync.connections')->value('last_error'))->toBe('The provider could not be read: Odoo failed (503).')
            ->and(DB::table('sync.reads')->where('id', $read)->value('error'))->not->toContain(ODOO_TOKEN);
    });
});

it('yields each item as a ProviderValue', function () {
    Http::fake(['odoo.test/*' => Http::response(odooAnswer([['code' => '1', 'price' => '1.00', 'available' => 1]]))]);

    expect(iterator_to_array(odooAdapter()->read(odooConnection(), null, null), false))->toEqual([new ProviderValue('1', '1.00', 1)]);
});
