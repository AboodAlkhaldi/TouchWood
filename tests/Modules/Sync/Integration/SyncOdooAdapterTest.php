<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnection;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnectionHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\SyncNow\SyncNow;
use Modules\Sync\Application\Command\SyncNow\SyncNowHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\Connections\InternalAddress;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ConnectionState;
use Modules\Sync\Domain\ValueObject\ProviderKind;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Infrastructure\Provider\OdooAdapter;
use Modules\Sync\Infrastructure\Provider\OdooAnswerTooLarge;
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sync\Support\FakeHostAddresses;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| Odoo's adapter (sync.md §2.4, Odoo's own JSON-2 API, owner 2026-11-09), against a faked HTTP API - no test
| reaches the network: each call's address, body and header; our codes as Odoo's ids, 500 a call; an id Odoo
| does not have left out; a code that cannot be an id never asked; the pace of a second between calls; a 429
| waited out once; every failure a short reason without the body, the address or the token; a malformed
| answer. Then whole reads through it - Sync Now, the hour's and the night's - and wiring a store through it.
*/

uses(RefreshDatabase::class);

const ODOO_BASE = 'https://example.odoo.com';
const ODOO_URL = ODOO_BASE.'/json/2/product.product/read';
const ODOO_TOKEN = 'odoo-token-SECRET-1';

beforeEach(function () {
    // The waits are recorded, never slept; each moves the frozen clock on as a real one would.
    CarbonImmutable::setTestNow('2026-11-10 12:00:00+00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Sleep::fake(false);
});

function odooConnection(string $address = ODOO_BASE, string $store = 'store-1', string $key = ODOO_TOKEN): Connection
{
    return new Connection($store, ProviderKind::Odoo, $address, [], $key, 'notice-secret', ConnectionState::Ok, null, 'staff-1');
}

/**
 * A ready product sold in KSA with these sizes; each size's id and code.
 *
 * @param  list<string>  $sizes
 * @return array{variants: list<string>, codes: list<string>}
 */
function odooProduct(array $sizes): array
{
    $ready = Px::ready($sizes);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));

    return ['variants' => $ready['variants'], 'codes' => array_map(odooCodeOf(...), $ready['variants'])];
}

function odooCodeOf(string $variantId): string
{
    return (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
}

function odooAdapter(): OdooAdapter
{
    return app(OdooAdapter::class);
}

/**
 * An item as Odoo answers it.
 *
 * @return array{id: int, list_price: mixed, free_qty: mixed}
 */
function odooItem(int|string $id, mixed $price = 10.0, mixed $stock = 1.0): array
{
    return ['id' => (int) $id, 'list_price' => $price, 'free_qty' => $stock];
}

/**
 * Items as Odoo writes them: `104.0` stays `104.0` (Laravel's fake would write `104`).
 *
 * @param  list<array<string, mixed>>  $items
 */
function odooJson(array $items): string
{
    return (string) json_encode($items, JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * Every value a read yields, as [code, price, stock].
 *
 * @param  list<string>  $codes
 * @return list<array{0: string, 1: mixed, 2: mixed}>
 */
function odooRead(array $codes, ?Connection $connection = null): array
{
    $values = [];

    foreach (odooAdapter()->read($connection ?? odooConnection(), $codes) as $value) {
        $values[] = [$value->code, $value->price, $value->stock];
    }

    return $values;
}

/**
 * Each call's body, in order.
 *
 * @return list<array<string, mixed>>
 */
function odooBodies(): array
{
    return array_values(Http::recorded()->map(fn (array $pair): array => (array) json_decode($pair[0]->body(), true))->all());
}

/**
 * The ids each call asked, in order.
 *
 * @return list<list<int>>
 */
function odooIdsAsked(): array
{
    return array_map(static fn (array $body): array => array_values(array_map(intval(...), (array) ($body['ids'] ?? []))), odooBodies());
}

/**
 * Answers each call with the ids it asked that are among these - as Odoo does, leaving the others out.
 *
 * @param  array<int, array{0: mixed, 1: mixed}>  $values  id => [list_price, free_qty]
 */
function odooAnswers(array $values): Closure
{
    return function (Request $request) use ($values) {
        $ids = (array) (json_decode($request->body(), true)['ids'] ?? []);
        $items = [];

        foreach ($ids as $id) {
            if (isset($values[$id])) {
                $items[] = odooItem($id, ...$values[$id]);
            }
        }

        return Http::response(odooJson($items));
    };
}

/**
 * The failure's reason in English, or null when it did not fail. Every reason of Odoo's is a code and its
 * values (ProviderReason, stage 5 step 10), never words as they came: one that is not fails the test.
 */
function odooFails(Closure $call): ?string
{
    try {
        $call();
    } catch (ConnectionFailed $failed) {
        $english = trans("sync::provider_failures.{$failed->coded?->code}", $failed->coded->values ?? [], 'en');

        // Stored as the code; the developers' message (the failed jobs screen) the English sentence, never JSON;
        // never the token, nor a chained error that could hold the address.
        expect($failed->coded)->not->toBeNull('Odoo\'s reason is kept as a code')
            ->and($failed->reason)->toBe($failed->coded?->stored())
            ->and($failed->getMessage())->toBe("The provider could not be read: {$english}")
            ->and($failed->getMessage())->not->toContain('{')
            ->and($failed->getPrevious())->toBeNull()
            ->and((string) $failed)->not->toContain(ODOO_TOKEN);

        return $english;
    }

    return null;
}

/**
 * Sizes of a draft product with these codes, written straight to Catalog's tables: many at once, cheaply -
 * only their codes matter to a read.
 *
 * @param  list<string>  $codes
 * @return list<string> their ids, in the codes' order
 */
function odooDraftSizes(array $codes): array
{
    $product = Px::product();
    $ids = [];

    foreach ($codes as $n => $code) {
        $id = strtolower((string) Str::ulid());
        DB::table('catalog.product_codes')->insert(['code' => $code, 'product_id' => $product, 'created_at' => now()]);
        DB::table('catalog.variants')->insert(['id' => $id, 'product_id' => $product, 'code' => $code, 'combination' => sprintf('%026d', $n + 1), 'position' => $n]);
        $ids[] = $id;
    }

    return $ids;
}

/** Wires KSA to Odoo, its test call answered by the fake's first answer. */
function odooWire(): void
{
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_BASE, ODOO_TOKEN, 'notice-secret'));
}

/** The store's oldest waiting read, run as its job runs it. */
function odooRunWaiting(): string
{
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('queued_at')->orderBy('id')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    return $read;
}

describe('the calls (§2.4)', function () {
    it('tests with one POST naming id 1, Odoo\'s two fields, the token as a bearer header, over HTTPS', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);

        odooAdapter()->test(odooConnection());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === ODOO_URL
            && $request->body() === '{"ids":[1],"fields":["list_price","free_qty"]}'
            && $request->header('Authorization') === ['Bearer '.ODOO_TOKEN]
            && $request->header('Content-Type') === ['application/json']
            && $request->header('Accept') === ['application/json']);

        expect(odooAdapter()->ready())->toBeTrue()
            ->and(odooAdapter()->fields())->toBe([]);
    });

    it('waits 30 seconds at most a read\'s call and 10 the test\'s (§9.3 #21), and follows no redirect', function () {
        $options = [];
        Http::fake(['example.odoo.com/*' => function (Request $request, array $sent) use (&$options) {
            $options[] = [$sent['timeout'] ?? null, $sent['connect_timeout'] ?? null, $sent['allow_redirects'] ?? null];

            return Http::response([]);
        }]);

        odooAdapter()->test(odooConnection());
        odooRead(['123']);

        expect($options)->toBe([[10, 10, false], [30, 10, false]]);
    });

    it('reads one batch: our codes sent as Odoo\'s ids, each answer given back under our code, price and stock as they came', function () {
        Http::fake(['example.odoo.com/*' => Http::response(odooJson([odooItem(1025, 104.0, 577.0), odooItem(1030, 69.5, 0.0)]))]);

        expect(odooRead(['1025', '1030']))->toBe([['1025', 104.0, 577.0], ['1030', 69.5, 0.0]])
            ->and(odooBodies())->toBe([['ids' => [1025, 1030], 'fields' => ['list_price', 'free_qty']]]);
        Http::assertSent(fn (Request $request): bool => $request->url() === ODOO_URL);
    });

    it('asks 500 ids a call at most: 501 codes are two calls, 1,201 three, every code once and in order', function (int $count, array $sizes) {
        $codes = array_map(static fn (int $n): string => (string) (10000 + $n), range(1, $count));
        Http::fake(['example.odoo.com/*' => odooAnswers(array_fill_keys(array_map('intval', $codes), [5.0, 1.0]))]);

        $values = odooRead($codes);

        expect(array_map('count', odooIdsAsked()))->toBe($sizes)
            ->and(array_merge(...odooIdsAsked()))->toBe(array_map('intval', $codes))
            ->and(array_column($values, 0))->toBe($codes);
    })->with([
        '501' => [501, [500, 1]],
        '1,201' => [1201, [500, 500, 201]],
    ]);

    it('leaves out an id Odoo does not have - only that one, with no error - and one it answers that was not asked', function () {
        Http::fake(['example.odoo.com/*' => Http::response(odooJson([odooItem(101, 10.0, 1.0), odooItem(999, 99.0, 9.0), odooItem(103, 30.0, 3.0)]))]);

        expect(odooRead(['101', '102', '103']))->toBe([['101', 10.0, 1.0], ['103', 30.0, 3.0]]);
    });

    it('takes from each call only the ids that call asked: one of ours asked in another call is not yielded twice', function () {
        $codes = array_map(static fn (int $n): string => (string) (10000 + $n), range(1, 501));
        // Each call answers its own ids, and the last one (asked in the second call) every time.
        Http::fake(['example.odoo.com/*' => function (Request $request) {
            $ids = array_values(array_map(intval(...), (array) (json_decode($request->body(), true)['ids'] ?? [])));

            return Http::response(odooJson(array_map(static fn (int $id): array => odooItem($id, 5.0, 1.0), array_values(array_unique([...$ids, 10501])))));
        }]);

        $values = array_column(odooRead($codes), 0);

        expect(count(odooIdsAsked()))->toBe(2)
            ->and($values)->toBe($codes);
    });

    it('answers nothing, with no error, when Odoo has none of the ids asked', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);

        expect(odooRead(['101', '102']))->toBe([]);
        Http::assertSentCount(1);
    });

    it('trims and asks each code once, and never asks a code that cannot be an Odoo id', function () {
        Http::fake(['example.odoo.com/*' => Http::response(odooJson([odooItem(7, 1.0, 1.0), odooItem(8, 2.0, 2.0)]))]);

        // A leading zero, a letter, a sign, a point, a space inside, past Odoo's int4, 0, empty: none is an id.
        expect(odooRead([' 7 ', '7', '0123', '12a', '-3', '1.5', '1 2', '2147483648', '9999999999', '1234567890123456789', '0', '', '8']))->toBe([['7', 1.0, 1.0], ['8', 2.0, 2.0]])
            ->and(odooIdsAsked())->toBe([[7, 8]]);
    });

    it('asks Odoo\'s largest id, 2147483647, and nothing past it', function () {
        Http::fake(['example.odoo.com/*' => Http::response(odooJson([odooItem(2147483647, 1.0, 1.0)]))]);

        expect(odooRead(['2147483647', '2147483648']))->toBe([['2147483647', 1.0, 1.0]])
            ->and(odooIdsAsked())->toBe([[2147483647]]);
    });

    it('asks nothing for no code, or none that can be an id', function () {
        Http::fake();

        expect(odooRead([]))->toBe([])
            ->and(odooRead(['0123', 'abc']))->toBe([]);
        Http::assertNothingSent();
    });

    it('takes an address written with a closing slash', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);

        odooAdapter()->test(odooConnection(ODOO_BASE.'/'));

        Http::assertSent(fn (Request $request): bool => $request->url() === ODOO_URL);
    });
});

describe('the answer', function () {
    it('passes list_price and free_qty on as they came - false for an empty field too - names one left out or null, and ignores other fields', function () {
        Http::fake(['example.odoo.com/*' => Http::response('[{"id": 1, "list_price": 104.0, "free_qty": 577.0, "name": "Oak"}, {"id": 2, "list_price": false, "free_qty": 2.5}, {"id": 3, "free_qty": null}]')]);

        $values = iterator_to_array(odooAdapter()->read(odooConnection(), ['1', '2', '3']), false);

        expect($values)->toEqual([
            new ProviderValue('1', 104.0, 577.0),
            new ProviderValue('2', false, 2.5),
            new ProviderValue('3', null, null, ['price', 'stock']),
        ]);
    });

    it('yields an id that comes twice twice, so the read skips both (§2.4)', function () {
        Http::fake(['example.odoo.com/*' => Http::response(odooJson([odooItem(5, 1.0, 1.0), odooItem(5, 2.0, 2.0)]))]);

        expect(odooRead(['5']))->toBe([['5', 1.0, 1.0], ['5', 2.0, 2.0]]);
    });

    it('fails a malformed answer with a short reason', function (string $body, string $reason) {
        Http::fake(['example.odoo.com/*' => Http::response($body)]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe("Odoo's answer is not as agreed: {$reason}.")
            ->and(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe("Odoo's answer is not as agreed: {$reason}.");
    })->with([
        'not JSON' => ['<html>Odoo</html>', 'it is not JSON'],
        'an object, not a list' => ['{"result": [{"id": 1}]}', 'it is not a list'],
        'a number' => ['5', 'it is not a list'],
        'an item not an object' => ['[1]', 'an item has no whole-number id'],
        'an item without an id' => ['[{"list_price": 1.0}]', 'an item has no whole-number id'],
        'an id as text' => ['[{"id": "1"}]', 'an item has no whole-number id'],
        'an id with a fraction' => ['[{"id": 1.5}]', 'an item has no whole-number id'],
        'an id too long for a whole number' => ['[{"id": 123456789012345678901234567890}]', 'an item has no whole-number id'],
        'one bad item among good ones' => ['[{"id": 1, "list_price": 1.0, "free_qty": 1.0}, {"id": false}]', 'an item has no whole-number id'],
    ]);
});

describe('one id Odoo refuses (§2.4, amendment 5)', function () {
    it('asks halves until it finds the id refused, gives it back refused, and reads the rest', function (int $status) {
        Http::fake(['example.odoo.com/*' => function (Request $request) use ($status) {
            $ids = array_values(array_map(intval(...), (array) (json_decode($request->body(), true)['ids'] ?? [])));

            return in_array(102, $ids, true)
                ? Http::response(['error' => 'no access to one record'], $status)
                : Http::response(odooJson(array_map(static fn (int $id): array => odooItem($id, (float) $id, 1.0), $ids)));
        }]);

        $values = iterator_to_array(odooAdapter()->read(odooConnection(), ['101', '102', '103']), false);

        expect($values)->toEqual([
            new ProviderValue('101', 101.0, 1.0),
            new ProviderValue('103', 103.0, 1.0),
            new ProviderValue('102', null, null, refused: $status),
        ])
            // All three, then 101 alone, then 102 and 103, then each.
            ->and(odooIdsAsked())->toBe([[101, 102, 103], [101], [102, 103], [102], [103]]);
    })->with(['403' => 403, '400' => 400, '404' => 404, '422' => 422]);

    it('fails the read for a wrong token, too many calls, their fault or a redirect - never asking halves', function (int $status, string $reason) {
        Http::fake(['example.odoo.com/*' => Http::response('', $status, $status === 429 ? ['Retry-After' => '1'] : [])]);

        expect(odooFails(fn () => odooRead(['101', '102', '103'])))->toBe($reason)
            ->and(array_map('count', odooIdsAsked()))->toBe($status === 429 ? [3, 3] : [3]);
    })->with([
        '401' => [401, 'Odoo refused the token (401).'],
        '429' => [429, 'Odoo answered 429 (too many calls) twice.'],
        '503' => [503, 'Odoo failed (503).'],
        '302' => [302, 'Odoo answered 302.'],
    ]);

    it('takes more than 10 ids refused in one read for the token\'s: the read fails with Odoo\'s reason', function () {
        // Ids 1-11 refused, 12-40 answered.
        Http::fake(['example.odoo.com/*' => function (Request $request) {
            $ids = array_values(array_map(intval(...), (array) (json_decode($request->body(), true)['ids'] ?? [])));

            return $ids !== [] && min($ids) <= 11 ? Http::response('', 403) : Http::response(odooJson(array_map(static fn (int $id): array => odooItem($id), $ids)));
        }]);

        expect(odooFails(fn () => odooRead(array_map(static fn (int $n): string => (string) $n, range(1, 40)))))
            ->toBe('Odoo refused access (403): the token\'s user may not read products.');
    });

    it('reads on with 10 ids refused, each given back refused', function () {
        Http::fake(['example.odoo.com/*' => function (Request $request) {
            $ids = array_values(array_map(intval(...), (array) (json_decode($request->body(), true)['ids'] ?? [])));

            return $ids !== [] && min($ids) <= 10 ? Http::response('', 403) : Http::response(odooJson(array_map(static fn (int $id): array => odooItem($id), $ids)));
        }]);

        $values = iterator_to_array(odooAdapter()->read(odooConnection(), array_map(static fn (int $n): string => (string) $n, range(1, 40))), false);

        expect(count($values))->toBe(40)
            ->and(array_map(static fn (ProviderValue $value): string => $value->code, array_values(array_filter($values, static fn (ProviderValue $value): bool => $value->refused === 403))))
            ->toBe(array_map('strval', range(1, 10)));
    });

    it('fails a read of more than one id whose every id is refused: the token\'s user may read none', function () {
        Http::fake(['example.odoo.com/*' => Http::response('', 403)]);

        expect(odooFails(fn () => odooRead(['1', '2', '3'])))->toBe('Odoo refused access (403): the token\'s user may not read products.');
    });

    it('gives a one-id call refused back as that code, refused, without failing', function () {
        Http::fake(['example.odoo.com/*' => Http::response('', 403)]);

        expect(iterator_to_array(odooAdapter()->read(odooConnection(), ['55']), false))->toEqual([new ProviderValue('55', null, null, refused: 403)])
            ->and(odooIdsAsked())->toBe([[55]]);
    });
});

describe('an answer past 5 MB (§2.4, amendment 5)', function () {
    it('fails an answer whose Content-Length says more than 5 MB, or whose body is more, in a read and the test alike', function (array $headers, string $body) {
        Http::fake(['example.odoo.com/*' => Http::response($body, 200, $headers)]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe("Odoo's answer is not as agreed: it is larger than 5 MB.")
            ->and(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe("Odoo's answer is not as agreed: it is larger than 5 MB.");
    })->with([
        'its length said' => [['Content-Length' => (string) (OdooAdapter::MOST_ANSWER_BYTES + 1)], '[]'],
        'its body' => [[], '['.str_repeat(' ', OdooAdapter::MOST_ANSWER_BYTES).']'],
    ]);

    it('reads an answer of 5 MB', function () {
        Http::fake(['example.odoo.com/*' => Http::response('['.str_repeat(' ', OdooAdapter::MOST_ANSWER_BYTES - 2).']', 200, ['Content-Length' => (string) OdooAdapter::MOST_ANSWER_BYTES])]);

        expect(odooRead(['1']))->toBe([]);
    });

    it('stops the transfer itself: its headers past 5 MB, or its progress past it', function () {
        $checked = false;
        // The callbacks the call hands the HTTP client, tried as the client would call them.
        Http::fake(['example.odoo.com/*' => function (Request $request, array $options) use (&$checked) {
            $headers = $options['on_headers'] ?? null;
            $progress = $options['progress'] ?? null;

            expect($headers)->toBeCallable()
                ->and($progress)->toBeCallable()
                ->and(fn () => $headers(new Psr7Response(200, ['Content-Length' => (string) (OdooAdapter::MOST_ANSWER_BYTES + 1)])))->toThrow(OdooAnswerTooLarge::class)
                ->and(fn () => $progress(OdooAdapter::MOST_ANSWER_BYTES + 1, 0, 0, 0))->toThrow(OdooAnswerTooLarge::class)
                ->and(fn () => $progress(0, OdooAdapter::MOST_ANSWER_BYTES + 1, 0, 0))->toThrow(OdooAnswerTooLarge::class);

            // Within 5 MB, or its length unsaid: they let it come.
            $headers(new Psr7Response(200, ['Content-Length' => (string) OdooAdapter::MOST_ANSWER_BYTES]));
            $headers(new Psr7Response(200));
            $progress(OdooAdapter::MOST_ANSWER_BYTES, OdooAdapter::MOST_ANSWER_BYTES, 0, 0);
            $checked = true;

            return Http::response([]);
        }]);

        expect(odooRead(['1']))->toBe([])
            ->and($checked)->toBeTrue();
    });

    it('says the transfer the callbacks stopped as too large, never as Odoo not reached', function () {
        Http::fake(['example.odoo.com/*' => function (Request $request, array $options) {
            try {
                $options['progress'](0, OdooAdapter::MOST_ANSWER_BYTES + 1, 0, 0);
            } catch (OdooAnswerTooLarge) {
                // As cURL reports a transfer its progress callback stopped.
            }

            return Create::rejectionFor(new ConnectException('cURL error 42: Callback aborted for '.ODOO_URL, $request->toPsrRequest()));
        }]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe("Odoo's answer is not as agreed: it is larger than 5 MB.");
    });
});

describe('the read\'s log line (§2.4, amendment 5)', function () {
    it('logs one line a read - ids, calls, seconds - never a code or the token, a failed one too', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = [$message->level, $message->message, $message->context];
        });
        Http::fake(['example.odoo.com/*' => Http::sequence()->push('[]')->push('[]')->push('', 503)]);
        $codes = array_map(static fn (int $n): string => (string) (70000 + $n), range(1, 501));

        odooRead($codes);
        odooFails(fn () => odooRead(['70001']));

        expect($logged)->toHaveCount(2)
            ->and($logged[0][0])->toBe('info')
            ->and($logged[0][1])->toBe('Sync read Odoo.')
            ->and($logged[0][2])->toMatchArray(['store' => 'store-1', 'ids' => 501, 'calls' => 2, 'refused' => 0, 'done' => true])
            ->and($logged[0][2]['seconds'])->toBeGreaterThanOrEqual(1.0)
            ->and($logged[1][2])->toMatchArray(['ids' => 1, 'calls' => 1, 'done' => false])
            ->and(json_encode($logged))->not->toContain('70001')
            ->and(json_encode($logged))->not->toContain(ODOO_TOKEN);
    });
});

describe('the pace (§2.4; §9.1 #8)', function () {
    it('waits at least a second between a store\'s calls, a read after a test too, and never before the first', function () {
        $at = [];
        Http::fake(['example.odoo.com/*' => function () use (&$at) {
            $at[] = Carbon::now()->getPreciseTimestamp(3);

            return Http::response([]);
        }]);

        odooAdapter()->test(odooConnection());
        // Three calls: 500, 500, 1.
        odooRead(array_map(static fn (int $n): string => (string) $n, range(1, 1001)));

        $gaps = array_map(static fn (float $then, float $now): float => $now - $then, array_slice($at, 0, -1), array_slice($at, 1));

        expect($at)->toHaveCount(4)
            ->and(array_filter($gaps, static fn (float $gap): bool => $gap < 1000.0))->toBe([]);
        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(fn (CarbonInterval $wait): bool => $wait->totalMilliseconds >= 1000, 3);
    });

    it('keeps each store to its own pace', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);

        odooAdapter()->test(odooConnection(store: 'store-1'));
        odooAdapter()->test(odooConnection(store: 'store-2'));

        Sleep::assertNeverSlept();
    });
});

describe('too many calls (429)', function () {
    it('waits Retry-After, then asks once more', function () {
        Http::fake(['example.odoo.com/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push(odooJson([odooItem(101, 10.0, 1.0)]))]);

        expect(odooRead(['101']))->toBe([['101', 10.0, 1.0]])
            ->and(odooIdsAsked())->toBe([[101], [101]]);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    });

    it('takes a Retry-After of 0 and still keeps the second between calls', function () {
        $at = [];
        Http::fake(['example.odoo.com/*' => function () use (&$at) {
            $at[] = Carbon::now()->getPreciseTimestamp(3);

            return count($at) === 1 ? Http::response('', 429, ['Retry-After' => '0']) : Http::response(odooJson([odooItem(1, 1.0, 1.0)]));
        }]);

        expect(odooRead(['1']))->toBe([['1', 1.0, 1.0]])
            ->and($at)->toHaveCount(2)
            ->and($at[1] - $at[0])->toBeGreaterThanOrEqual(1000.0);
    });

    it('fails on a second 429', function () {
        Http::fake(['example.odoo.com/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '3'])
            ->push('', 429, ['Retry-After' => '3'])]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe('Odoo answered 429 (too many calls) twice.');
        Http::assertSentCount(2);
    });

    it('waits 60 seconds at most - for a Retry-After past it, or none, or a date', function (?string $retryAfter) {
        Http::fake(['example.odoo.com/*' => Http::sequence()
            ->push('', 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])
            ->push([])]);

        odooRead(['1']);

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    })->with(['past it' => '600', 'none' => null, 'a date' => 'Wed, 11 Nov 2026 07:28:00 GMT']);

    it('fails the Super Admin\'s test at once: it runs in a web request, 10 seconds at most', function () {
        Http::fake(['example.odoo.com/*' => Http::response('', 429, ['Retry-After' => '5'])]);

        expect(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe('Odoo answered 429 (too many calls).');
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });
});

describe('failures: a short reason, never the body, the address or the token (§2.1)', function () {
    it('names what failed and its status code', function (int $status, string $reason) {
        Http::fake(['example.odoo.com/*' => Http::response(['error' => 'Body text with '.ODOO_TOKEN], $status)]);

        // Two ids: a 403, 400 or 404 for every id of a read is not one id's (amendment 5), so it fails too.
        $read = odooFails(fn () => odooRead(['1', '2']));
        $test = odooFails(fn () => odooAdapter()->test(odooConnection()));

        expect($read)->toBe($reason)
            ->and($test)->toBe($reason)
            ->and(str_contains((string) $read, 'Body text') || str_contains((string) $read, ODOO_TOKEN) || str_contains((string) $read, 'odoo.com'))->toBeFalse();
    })->with([
        'a wrong token (401)' => [401, 'Odoo refused the token (401).'],
        'no right to read products (403)' => [403, 'Odoo refused access (403): the token\'s user may not read products.'],
        '400' => [400, 'Odoo refused the request (400).'],
        '500' => [500, 'Odoo failed (500).'],
        '503' => [503, 'Odoo failed (503).'],
        '404' => [404, 'Odoo answered 404.'],
        'a redirect, never followed' => [302, 'Odoo answered 302.'],
    ]);

    it('says a timeout and a refused connection without the address', function () {
        Http::fake(['example.odoo.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received for '.ODOO_URL)
            ->pushFailedConnection('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received for '.ODOO_URL)
            ->pushFailedConnection('cURL error 7: Failed to connect to example.odoo.com port 443 for '.ODOO_URL)]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe('Odoo did not answer within 30 seconds.')
            ->and(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe('Odoo did not answer within 10 seconds.')
            ->and(odooFails(fn () => odooRead(['1'])))->toBe('Odoo could not be reached.');
    });

    it('names the time that ran out: reaching Odoo (10 seconds), or its answer', function () {
        Http::fake(['example.odoo.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Connection timed out after 10002 milliseconds for '.ODOO_URL)
            ->pushFailedConnection('cURL error 28: Resolving timed out after 10000 milliseconds for '.ODOO_URL)]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe('Odoo could not be reached within 10 seconds.')
            ->and(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe('Odoo could not be reached within 10 seconds.');
    });

    it('judges a status whose transfer then failed by its status, its body never read', function () {
        Http::fake(['example.odoo.com/*' => fn (Request $request) => Create::rejectionFor(new ResponseTransferException(
            'Transfer failed, body: secret '.ODOO_TOKEN,
            $request->toPsrRequest(),
            new Psr7Response(503, [], 'secret '.ODOO_TOKEN),
        ))]);

        expect(odooFails(fn () => odooRead(['1'])))->toBe('Odoo failed (503).')
            ->and(odooFails(fn () => odooAdapter()->test(odooConnection())))->toBe('Odoo failed (503).');
    });

    it('waits out a 429 whose transfer failed, then asks once more', function () {
        $calls = 0;
        Http::fake(['example.odoo.com/*' => function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? Create::rejectionFor(new ResponseTransferException('Transfer failed', $request->toPsrRequest(), new Psr7Response(429, ['Retry-After' => '4'], 'slow')))
                : Http::response(odooJson([odooItem(1, 1.0, 1.0)]));
        }]);

        expect(odooRead(['1']))->toBe([['1', 1.0, 1.0]])
            ->and($calls)->toBe(2);
        Sleep::assertSlept(fn (CarbonInterval $wait): bool => (int) $wait->totalSeconds === 4);
    });

    it('says a key that cannot be sent without the key', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);
        $key = "token-with\nSECRET-line";

        $reason = odooFails(fn () => odooAdapter()->test(odooConnection(key: $key)));

        expect($reason)->toBe('Odoo could not be asked: the address or the key holds a character that cannot be sent.')
            ->and(str_contains((string) $reason, 'SECRET'))->toBeFalse();
    });

    it('calls nothing at an address that is not HTTPS', function () {
        Http::fake();

        expect(odooFails(fn () => odooAdapter()->test(odooConnection('http://example.odoo.com'))))->toBe('The address must start with https://.')
            ->and(odooFails(fn () => odooRead(['1'], odooConnection('http://example.odoo.com'))))->toBe('The address must start with https://.');
        Http::assertNothingSent();
    });

    it('never shows the key when the connection is dumped', function () {
        expect(print_r(odooConnection(), true))->not->toContain(ODOO_TOKEN)
            ->and(var_export(odooConnection()->__debugInfo(), true))->not->toContain(ODOO_TOKEN);
    });
});

describe('the address (§2.4)', function () {
    it('knows an address inside our network - loopback, private, link-local, unique-local, unspecified - and one outside', function (string $ip, bool $inside) {
        expect(InternalAddress::is($ip))->toBe($inside);
    })->with([
        '127.0.0.1' => ['127.0.0.1', true],
        '127.255.255.254' => ['127.255.255.254', true],
        '10.0.0.1' => ['10.0.0.1', true],
        '172.16.0.1' => ['172.16.0.1', true],
        '172.31.255.255' => ['172.31.255.255', true],
        '172.15.255.255' => ['172.15.255.255', false],
        '172.32.0.1' => ['172.32.0.1', false],
        '192.168.1.1' => ['192.168.1.1', true],
        '192.169.0.1' => ['192.169.0.1', false],
        '169.254.169.254' => ['169.254.169.254', true],
        '0.0.0.0' => ['0.0.0.0', true],
        '8.8.8.8' => ['8.8.8.8', false],
        '::1' => ['::1', true],
        '::' => ['::', true],
        'fe80::1' => ['fe80::1', true],
        'febf::1' => ['febf::1', true],
        'fec0::1' => ['fec0::1', false],
        'fc00::1' => ['fc00::1', true],
        'fdff::1' => ['fdff::1', true],
        'fe00::1' => ['fe00::1', false],
        '::ffff:10.0.0.1' => ['::ffff:10.0.0.1', true],
        '::ffff:8.8.8.8' => ['::ffff:8.8.8.8', false],
        '2001:4860:4860::8888' => ['2001:4860:4860::8888', false],
        'not an IP' => ['odoo', true],
    ]);

    it('takes the account\'s address alone, a closing slash dropped', function (string $given, string $kept) {
        expect(odooAdapter()->address($given))->toBe($kept);
    })->with([
        'as written' => ['https://example.odoo.com', 'https://example.odoo.com'],
        'a closing slash' => ['https://example.odoo.com/', 'https://example.odoo.com'],
        'a port' => ['https://odoo.example.com:8443', 'https://odoo.example.com:8443'],
    ]);

    it('refuses a path, a query or a fragment, each by its own rule', function (string $given, string $rule) {
        try {
            odooAdapter()->address($given);
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = [$error->attribute, $error->context()['rule'] ?? null];
        }

        expect($refused)->toBe(['address', $rule]);
    })->with([
        'the whole call\'s address' => ['https://example.odoo.com/json/2/product.product/read', 'address_path'],
        'another path' => ['https://example.odoo.com/odoo', 'address_path'],
        'the old custom path' => ['https://example.odoo.com/touchwood/v1', 'address_path'],
        'two closing slashes' => ['https://example.odoo.com//x', 'address_path'],
        'a query' => ['https://example.odoo.com?db=tw', 'address_query'],
        'an empty query' => ['https://example.odoo.com/?', 'address_query'],
        'a fragment' => ['https://example.odoo.com#x', 'address_query'],
        'a port past 65535' => ['https://example.odoo.com:99999', 'address_malformed'],
        'a port not a number' => ['https://example.odoo.com:abc', 'address_malformed'],
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
        Http::fake(['example.odoo.com/*' => Http::sequence()
            ->push(['error' => 'bad token'], 401)
            ->push([])]);

        expect(fn () => odooWire())->toThrow(ConnectionFailed::class, 'The provider could not be read: Odoo refused the token (401).')
            ->and(DB::table('sync.connections')->count())->toBe(0);

        odooWire();

        expect(DB::table('sync.connections')->where('store_id', Fx::storeId('sa'))->first(['state', 'address']))->toMatchObject(['state' => 'OK', 'address' => ODOO_BASE])
            ->and(Crypt::decryptString((string) DB::table('sync.connections')->value('key_encrypted')))->toBe(ODOO_TOKEN)
            ->and((string) DB::table('sync.connections')->value('key_encrypted'))->not->toContain(ODOO_TOKEN)
            ->and(DB::table('sync.reads')->where('store_id', Fx::storeId('sa'))->pluck('kind')->all())->toBe(['NOW'])
            ->and(odooIdsAsked())->toBe([[1], [1]]);
        Http::assertSent(fn (Request $request): bool => $request->url() === ODOO_URL && $request->header('Authorization') === ['Bearer '.ODOO_TOKEN]);
    });

    it('refuses an address with a path - the whole call\'s too - before any call', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);

        expect(fn () => app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_URL, ODOO_TOKEN, 'notice-secret')))->toThrow(InvalidSyncInput::class, 'Invalid address. (address_path)');
        Http::assertNothingSent();

        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', ODOO_BASE.'/', ODOO_TOKEN, 'notice-secret'));

        expect(DB::table('sync.connections')->value('address'))->toBe(ODOO_BASE);
        Http::assertSent(fn (Request $request): bool => $request->url() === ODOO_URL);
    });

    it('refuses an address inside our network, as written or as it resolves, before any call (§2.4)', function (string $address, array $resolves) {
        Http::fake();
        FakeHostAddresses::bound()->names = $resolves;

        try {
            app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', $address, ODOO_TOKEN, 'notice-secret'));
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = $error->context();
        }

        expect($refused)->toBe(['attribute' => 'address', 'rule' => 'address_internal'])
            ->and(DB::table('sync.connections')->count())->toBe(0);
        Http::assertNothingSent();
    })->with([
        'loopback' => ['https://127.0.0.1', []],
        'private' => ['https://10.20.30.40', []],
        'private 172.16/12' => ['https://172.20.0.5', []],
        'private 192.168/16' => ['https://192.168.0.10:8443', []],
        'link-local, the cloud\'s metadata' => ['https://169.254.169.254', []],
        'unspecified' => ['https://0.0.0.0', []],
        'IPv6 loopback' => ['https://[::1]', []],
        'IPv6 link-local' => ['https://[fe80::1]', []],
        'IPv6 unique-local' => ['https://[fd12:3456::1]', []],
        'IPv4 written as IPv6' => ['https://[::ffff:10.0.0.1]', []],
        'a name resolving inside' => ['https://odoo.internal', ['odoo.internal' => ['10.0.0.7']]],
        'a name with one address inside' => ['https://example.odoo.com', ['example.odoo.com' => ['93.184.216.34', '192.168.5.5']]],
    ]);

    it('wires an address outside our network, as written or as it resolves', function () {
        Http::fake(['example.odoo.com/*' => Http::response([]), '93.184.216.34/*' => Http::response([])]);
        FakeHostAddresses::bound()->names = ['example.odoo.com' => ['93.184.216.34', '2606:2800:220:1::1']];

        odooWire();
        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(Fx::storeId('sa'), 'ODOO', 'https://93.184.216.34', 'new-token', 'new-secret'));

        expect(DB::table('sync.connections')->value('address'))->toBe('https://93.184.216.34');
    });

    it('refuses an address the HTTP client cannot call, by its own rule, before any call', function (string $address) {
        Http::fake();

        try {
            app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', $address, ODOO_TOKEN, 'notice-secret'));
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = $error->context();
        }

        expect($refused)->toBe(['attribute' => 'address', 'rule' => 'address_malformed'])
            ->and(DB::table('sync.connections')->count())->toBe(0);
        Http::assertNothingSent();
    })->with([
        'a backslash in the host' => ['https://example.odoo.com\\evil'],
        'a port past 65535' => ['https://example.odoo.com:99999'],
        'a port not a number' => ['https://example.odoo.com:abc'],
        'port 0' => ['https://example.odoo.com:0'],
    ]);

    it('never sends the stored token to a new address: a key or a secret left empty is refused, nothing called (owner, 2026-10-20)', function (?string $key, ?string $secret, string $field) {
        Http::fake(['example.odoo.com/*' => Http::response([]), 'other.odoo.com/*' => Http::response([])]);
        odooWire();

        try {
            app(ChangeConnectionHandler::class)->handle(new ChangeConnection(Fx::storeId('sa'), 'ODOO', 'https://other.odoo.com', $key, $secret));
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = $error->attribute;
        }

        expect($refused)->toBe($field);
        // The wiring's own test call only.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'other.odoo.com'));
    })->with([
        'the key empty' => ['', 'new-secret', 'key'],
        'the secret empty' => ['new-token', null, 'secret'],
        'both empty' => [null, null, 'key'],
    ]);

    it('tests a new address with the key typed again, and keeps the stored ones at the same address', function () {
        Http::fake(['example.odoo.com/*' => Http::response([]), 'other.odoo.com/*' => Http::response([])]);
        odooWire();

        // The same address, written with a closing slash, the boxes left empty: the stored key is sent there.
        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(Fx::storeId('sa'), 'ODOO', ODOO_BASE.'/', null, null));
        // A new address, both typed again: the new key goes to it.
        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(Fx::storeId('sa'), 'ODOO', 'https://other.odoo.com', 'new-token', 'new-secret'));

        $sent = Http::recorded()->map(fn (array $pair): array => [$pair[0]->url(), $pair[0]->header('Authorization')[0] ?? null])->values()->all();

        expect($sent)->toBe([
            [ODOO_URL, 'Bearer '.ODOO_TOKEN],
            [ODOO_URL, 'Bearer '.ODOO_TOKEN],
            ['https://other.odoo.com/json/2/product.product/read', 'Bearer new-token'],
        ])
            ->and(DB::table('sync.connections')->value('address'))->toBe('https://other.odoo.com')
            ->and(Crypt::decryptString((string) DB::table('sync.connections')->value('key_encrypted')))->toBe('new-token')
            ->and(Crypt::decryptString((string) DB::table('sync.connections')->value('secret_encrypted')))->toBe('new-secret');
    });

    it('asks every size of ours that is not archived - drafts and sizes switched off too - and writes what Odoo answers', function () {
        ['variants' => [$on, $off, $missing], 'codes' => [$onCode, $offCode, $missingCode]] = odooProduct(['10 cm', '20 cm', '30 cm']);
        DB::table('catalog.store_variants')->where('variant_id', $off)->update(['is_active' => false]);
        [$draft, $archived] = odooDraftSizes(['7000001', '7000002']);
        DB::table('catalog.variants')->where('id', $archived)->update(['is_archived' => true]);
        // Odoo answers our first two and the draft's; it has no such id as the third (nor id 1, the test's).
        Http::fake(['example.odoo.com/*' => odooAnswers([
            (int) $onCode => [104.0, 577.0],
            (int) $offCode => [69.0, 42.0],
            7000001 => [15.0, 3.0],
        ])]);
        odooWire();

        $read = odooRunWaiting();
        $asked = odooIdsAsked()[1];
        sort($asked);
        $ours = array_map('intval', array_values(DB::table('catalog.variants as v')->join('catalog.products as p', 'p.id', '=', 'v.product_id')
            ->where('v.is_archived', false)->where('p.stage', '<>', 'ARCHIVED')->orderBy('v.code')->pluck('v.code')->all()));
        sort($ours);

        expect($asked)->toBe($ours)
            ->and($asked)->toContain((int) $missingCode, 7000001)
            ->and($asked)->not->toContain(7000002)
            ->and(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE')
            ->and(Rx::retail('sa', $on)?->amount_minor)->toBe(10400)
            ->and(Ix::stock('sa', $on)?->in_stock)->toBe(577)
            // Off in the store: priced, left off, listed (§1.4 #4).
            ->and(Rx::retail('sa', $off)?->amount_minor)->toBe(6900)
            ->and(Ix::stock('sa', $off)?->in_stock)->toBe(42)
            ->and(Rx::retail('sa', $draft)?->amount_minor)->toBe(1500)
            ->and(Rx::retail('sa', $missing))->toBeNull()
            // Only the night's read reports ours Odoo lacks (§1.3).
            ->and(DB::table('sync.report_entries')->where('kind', 'NOT_IN_PROVIDER')->count())->toBe(0)
            ->and(DB::table('sync.report_entries')->where('kind', 'OFF_OR_DRAFT_PRICED')->whereNull('closed_at')->pluck('variant_id')->all())->toContain($off);
    });

    it('skips a stock with a fraction, and a price or a stock Odoo left empty (false) or sent as no number, each a bad value for that code alone, and reads on', function () {
        ['variants' => $sizes, 'codes' => $codes] = odooProduct(['10 cm', '20 cm', '30 cm', '40 cm', '50 cm', '60 cm']);
        $body = '['
            .'{"id": '.$codes[0].', "list_price": false, "free_qty": 3.0},'
            .'{"id": '.$codes[1].', "list_price": "12.50", "free_qty": 2.5},'
            .'{"id": '.$codes[2].', "list_price": 10.0, "free_qty": false},'
            .'{"id": '.$codes[3].', "list_price": [1], "free_qty": {"n": 1}},'
            .'{"id": '.$codes[4].', "list_price": 10.123, "free_qty": -4.0},'
            .'{"id": '.$codes[5].', "list_price": 120.0, "free_qty": 7.0}'
            .']';
        Http::fake(['example.odoo.com/*' => Http::sequence()->push([])->push($body)]);
        odooWire();

        $read = odooRunWaiting();

        $refused = DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->whereNull('closed_at')->get(['variant_id', 'detail'])
            ->mapWithKeys(fn ($row): array => [(string) $row->variant_id => json_decode((string) $row->detail, true)['refused'] ?? null])->all();

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE')
            ->and($refused)->toEqual([$sizes[0] => 'price', $sizes[1] => 'stock', $sizes[2] => 'stock', $sizes[3] => 'both', $sizes[4] => 'both'])
            // A fraction is never rounded: that size keeps no stock; below 0 is kept as 0 (§1.5 #2); 10.123 has
            // more decimals than the riyal (§1.4 #3).
            ->and(array_map(fn (string $size): ?int => Rx::retail('sa', $size)?->amount_minor, $sizes))->toBe([null, 1250, 1000, null, null, 12000])
            // -4.0 is kept as 0: a size with no stock row already counts 0, so none is written.
            ->and(array_map(fn (string $size): ?int => Ix::stock('sa', $size)?->in_stock, $sizes))->toBe([3, null, null, null, null, 7]);
    });

    it('reports a price or a stock null or left out as a bad value naming it, and takes the other when good', function () {
        ['variants' => $sizes, 'codes' => $codes] = odooProduct(['10 cm', '20 cm', '30 cm', '40 cm']);
        $body = '['
            .'{"id": '.$codes[0].', "list_price": null, "free_qty": 3.0},'
            .'{"id": '.$codes[1].', "list_price": 10.0},'
            .'{"id": '.$codes[2].'},'
            .'{"id": '.$codes[3].', "list_price": 120.0, "free_qty": 7.0}'
            .']';
        Http::fake(['example.odoo.com/*' => Http::sequence()->push([])->push($body)]);
        odooWire();

        $read = odooRunWaiting();

        $entries = DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->whereNull('closed_at')->get(['variant_id', 'detail'])
            ->mapWithKeys(fn ($row): array => [(string) $row->variant_id => json_decode((string) $row->detail, true)])->all();

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE')
            ->and($entries)->toEqual([
                $sizes[0] => ['price' => null, 'stock' => 3, 'refused' => 'price', 'missing' => ['price']],
                $sizes[1] => ['price' => 10, 'stock' => null, 'refused' => 'stock', 'missing' => ['stock']],
                $sizes[2] => ['price' => null, 'stock' => null, 'refused' => 'both', 'missing' => ['price', 'stock']],
            ])
            ->and(array_map(fn (string $size): ?int => Rx::retail('sa', $size)?->amount_minor, $sizes))->toBe([null, 1000, null, 12000])
            ->and(array_map(fn (string $size): ?int => Ix::stock('sa', $size)?->in_stock, $sizes))->toBe([3, null, null, 7]);
    });

    it('keeps "may be missing" for another provider: a null value is not sent, not a bad value (§2.1)', function () {
        ['variants' => [$size], 'codes' => [$code]] = odooProduct(['10 cm']);
        $fake = FakeProvider::bind();
        odooWire();
        $fake->values = [new ProviderValue($code, '15.00', null)];

        odooRunWaiting();

        expect(DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->count())->toBe(0)
            ->and(Rx::retail('sa', $size)?->amount_minor)->toBe(1500);
    });

    it('skips an object sent by any provider as a bad value, never failing the read', function () {
        ['variants' => [$size, $good], 'codes' => [$code, $other]] = odooProduct(['10 cm', '20 cm']);
        $fake = FakeProvider::bind();
        odooWire();
        $fake->values = [new ProviderValue($code, new stdClass, new stdClass), new ProviderValue($other, 12.5, 2)];

        $read = odooRunWaiting();

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE')
            ->and(json_decode((string) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->where('variant_id', $size)->value('detail'), true))->toBe(['price' => 'stdClass', 'stock' => 'stdClass', 'refused' => 'both'])
            ->and(Rx::retail('sa', $good)?->amount_minor)->toBe(1250);
    });

    it('reads a store whose codes take two calls, an id answered twice reported as a bad value', function () {
        ['variants' => $sizes, 'codes' => $codes] = odooProduct(['60 cm', '80 cm', '100 cm']);
        // Past 500 of our codes: two calls (codes 9000001-9000500 are drafts Odoo does not have).
        odooDraftSizes(array_map(static fn (int $n): string => (string) (9_000_000 + $n), range(1, 500)));
        [$a, $b, $twice] = $codes;
        Http::fake(['example.odoo.com/*' => function (Request $request) use ($a, $b, $twice) {
            $ids = (array) (json_decode($request->body(), true)['ids'] ?? []);
            $items = [];

            foreach ([$a => [120.0, 7.0], $b => [85.5, 4.0]] as $id => [$price, $stock]) {
                if (in_array((int) $id, $ids, true)) {
                    $items[] = odooItem($id, $price, $stock);
                }
            }

            if (in_array((int) $twice, $ids, true)) {
                $items[] = odooItem($twice, 50.0, 1.0);
                $items[] = odooItem($twice, 55.0, 2.0);
            }

            return Http::response(odooJson($items));
        }]);
        odooWire();

        $read = odooRunWaiting();

        $calls = array_slice(odooIdsAsked(), 1);
        $asked = array_merge(...$calls);

        // The test's call, then 503 codes or more in two: 500, then the rest.
        expect($calls)->toHaveCount(2)
            ->and(count($calls[0]))->toBe(500)
            ->and(count($calls[1]))->toBe(count($asked) - 500)
            ->and(count($asked))->toBeGreaterThanOrEqual(503)
            ->and(count(array_unique($asked)))->toBe(count($asked))
            ->and($asked)->toContain((int) $a, (int) $b, (int) $twice, 9_000_001, 9_000_500)
            ->and(Rx::retail('sa', $sizes[0])?->amount_minor)->toBe(12000)
            ->and(Ix::stock('sa', $sizes[0])?->in_stock)->toBe(7)
            ->and(Rx::retail('sa', $sizes[1])?->amount_minor)->toBe(8550)
            ->and(Ix::stock('sa', $sizes[1])?->in_stock)->toBe(4)
            ->and(Rx::retail('sa', $sizes[2]))->toBeNull()
            ->and(DB::table('sync.report_entries')->whereNull('closed_at')->where('kind', 'BAD_VALUE')->pluck('variant_id')->all())->toBe([$sizes[2]])
            ->and(DB::table('sync.reads')->where('id', $read)->first(['state', 'prices_changed', 'stock_changed']))->toMatchObject(['state' => 'DONE', 'prices_changed' => 2, 'stock_changed' => 2]);
    });

    it('writes nothing an earlier call gathered when a later call of the same read fails (§2.4, §1.7)', function () {
        odooProduct(['10 cm']);
        // Past 500 of our codes: the first call is answered for every id it asks, a price and a stock each; the
        // second fails.
        odooDraftSizes(array_map(static fn (int $n): string => (string) (9_100_000 + $n), range(1, 500)));
        $calls = 0;
        Http::fake(['example.odoo.com/*' => function (Request $request) use (&$calls) {
            $calls++;
            $ids = (array) (json_decode($request->body(), true)['ids'] ?? []);

            return match (true) {
                // The wiring's test.
                $calls === 1 => Http::response([]),
                $calls === 2 => Http::response(odooJson(array_values(array_map(static fn (int $id): array => odooItem($id, 99.0, 9.0), $ids)))),
                default => Http::response('', 503),
            };
        }]);
        odooWire();
        $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
        $failure = null;

        try {
            odooRunWaiting();
        } catch (ConnectionFailed $error) {
            // The job's attempt fails; ReadStoreJob retries it (§1.7).
            $failure = $error->getMessage();
        }

        expect($calls)->toBe(3)
            ->and(count(odooIdsAsked()[1]))->toBe(500)
            ->and($failure)->toBe('The provider could not be read: Odoo failed (503).')
            ->and(DB::table('pricing.retail_prices')->where('store_id', Fx::storeId('sa'))->count())->toBe(0)
            ->and(DB::table('inventory.stock')->where('store_id', Fx::storeId('sa'))->count())->toBe(0)
            ->and(DB::table('sync.read_lines')->where('read_id', $read)->count())->toBe(0);
    });

    it('reads through Odoo for Sync Now, the hour\'s and the night\'s reads alike - every code of ours - and the night\'s reports ours Odoo lacks', function () {
        ['variants' => [$here, $gone], 'codes' => [$hereCode, $goneCode]] = odooProduct(['10 cm', '20 cm']);
        Http::fake(['example.odoo.com/*' => function (Request $request) use ($hereCode) {
            $ids = (array) (json_decode($request->body(), true)['ids'] ?? []);

            return Http::response(in_array((int) $hereCode, $ids, true) ? odooJson([odooItem($hereCode, 30.0, 5.0)]) : '[]');
        }]);
        odooWire();
        // The wiring's own Now read first.
        odooRunWaiting();
        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Hourly));
        odooRunWaiting();
        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Nightly));
        odooRunWaiting();
        // Sync Now, as staff press it.
        app(SyncNowHandler::class)->handle(new SyncNow(Fx::storeId('sa')));
        odooRunWaiting();

        $everything = array_map(static function (array $ids): array {
            sort($ids);

            return $ids;
        }, array_slice(odooIdsAsked(), 1));

        expect(DB::table('sync.reads')->orderBy('queued_at')->orderBy('id')->get(['kind', 'state'])->map(fn ($row): array => [$row->kind, $row->state])->all())
            ->toBe([['NOW', 'DONE'], ['HOURLY', 'DONE'], ['NIGHTLY', 'DONE'], ['NOW', 'DONE']])
            ->and($everything)->toHaveCount(4)
            ->and(array_unique(array_map('json_encode', $everything)))->toHaveCount(1)
            ->and($everything[0])->toContain((int) $hereCode, (int) $goneCode)
            ->and(Rx::retail('sa', $here)?->amount_minor)->toBe(3000)
            ->and(DB::table('sync.report_entries')->where('kind', 'NOT_IN_PROVIDER')->whereNull('closed_at')->pluck('variant_id')->all())->toBe([$gone]);
        Http::assertSent(fn (Request $request): bool => $request->url() === ODOO_URL && $request->method() === 'POST');
    });

    it('asks a corrected code\'s read and a notice\'s read for their codes only', function () {
        ['codes' => [$code, $other]] = odooProduct(['10 cm', '20 cm']);
        Http::fake(['example.odoo.com/*' => Http::response([])]);
        odooWire();
        DB::table('sync.reads')->delete();

        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Code, [$code]));
        odooRunWaiting();
        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Notified, [$other, '0999']));
        odooRunWaiting();

        expect(array_slice(odooIdsAsked(), 1))->toBe([[(int) $code], [(int) $other]]);
    });

    it('reports a size whose id Odoo refuses alone as a bad value naming the status, and writes the rest', function () {
        ['variants' => [$good, $refused], 'codes' => [$goodCode, $refusedCode]] = odooProduct(['10 cm', '20 cm']);
        Http::fake(['example.odoo.com/*' => function (Request $request) use ($refusedCode) {
            $ids = array_values(array_map(intval(...), (array) (json_decode($request->body(), true)['ids'] ?? [])));

            return in_array((int) $refusedCode, $ids, true)
                ? Http::response('', 403)
                : Http::response(odooJson(array_map(static fn (int $id): array => odooItem($id, 40.0, 4.0), $ids)));
        }]);
        odooWire();

        $read = odooRunWaiting();

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE')
            ->and(Rx::retail('sa', $good)?->amount_minor)->toBe(4000)
            ->and(Ix::stock('sa', $good)?->in_stock)->toBe(4)
            ->and(Rx::retail('sa', $refused))->toBeNull()
            ->and(json_decode((string) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->whereNull('closed_at')->where('variant_id', $refused)->value('detail'), true))
            ->toBe(['price' => null, 'stock' => null, 'refused' => 'both', 'provider_refused' => 403])
            ->and(DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->where('variant_id', $good)->count())->toBe(0);
    });

    it('reads nothing, and is done, in a store with no size', function () {
        Http::fake(['example.odoo.com/*' => Http::response([])]);
        odooWire();
        DB::table('catalog.variants')->update(['is_archived' => true]);

        $read = odooRunWaiting();

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE')
            ->and(odooIdsAsked())->toBe([[1]]);
    });

    it('stores the short reason as a code and its values when a read fails, and never writes the token to a log, an exception or the audit', function (int $status, string $stored, string $english) {
        // Two sizes at least: a 403 for every id of a read is the token's, failing it (amendment 5).
        odooProduct(['10 cm', '20 cm']);
        $calls = 0;
        Http::fake(['example.odoo.com/*' => function () use (&$calls, $status) {
            // The wiring's test passes; every read call after it is refused.
            return ++$calls === 1 ? Http::response([]) : Http::response(['error' => 'Body text with '.ODOO_TOKEN], $status);
        }]);
        odooWire();
        $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $failure = null;

        try {
            odooRunWaiting();
        } catch (Throwable $error) {
            $failure = $error;
        }

        // As a worker logs a failed attempt: the exception with its whole trace.
        logger()->error('A read failed.', ['exception' => (string) $failure]);

        // The job's last attempt, with the very failure the read raised.
        Fx::asSystem(fn () => (new ReadStoreJob($read))->failed($failure));

        $audit = (string) DB::table('platform.audit_entries')->where('action', 'sync.read.failed')->where('subject_id', $read)->value('changes');

        expect($failure)->toBeInstanceOf(ConnectionFailed::class)
            ->and($failure?->getPrevious())->toBeNull()
            ->and((string) $failure)->not->toContain(ODOO_TOKEN)
            ->and(DB::table('sync.connections')->value('state'))->toBe('FAILING')
            ->and(DB::table('sync.connections')->value('last_error'))->toBe($stored)
            ->and(DB::table('sync.reads')->where('id', $read)->value('error'))->toBe($stored)
            ->and(DB::table('sync.report_entries')->where('kind', 'READ_FAILED')->whereNull('closed_at')->count())->toBe(1)
            ->and($failure?->getMessage())->toBe("The provider could not be read: {$english}")
            ->and($audit)->toContain($english)
            ->and($audit)->not->toContain(ODOO_TOKEN)
            // The worker's line, and the read's own (amendment 5): neither with the token.
            ->and($logged)->toHaveCount(2)
            ->and(implode("\n", $logged))->not->toContain(ODOO_TOKEN);
    })->with([
        'a wrong token' => [401, '{"code":"ODOO_REFUSED_TOKEN","values":{}}', 'Odoo refused the token (401).'],
        'no right to read' => [403, '{"code":"ODOO_REFUSED_ACCESS","values":{}}', 'Odoo refused access (403): the token\'s user may not read products.'],
        'their fault' => [503, '{"code":"ODOO_FAILED","values":{"status":503}}', 'Odoo failed (503).'],
    ]);
});
