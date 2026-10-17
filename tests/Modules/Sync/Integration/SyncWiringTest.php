<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Public\Contracts\StoreWiring as InventoryWiring;
use Modules\Pricing\Domain\Exception\ProviderOwnsPrice;
use Modules\Pricing\Public\Contracts\StoreWiring as PricingWiring;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnection;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnectionHandler;
use Modules\Sync\Application\Command\UnwireStore\UnwireStore;
use Modules\Sync\Application\Command\UnwireStore\UnwireStoreHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\Query\ViewConnection\ConnectionView;
use Modules\Sync\Application\Query\ViewConnection\ViewConnection;
use Modules\Sync\Application\Query\ViewConnection\ViewConnectionHandler;
use Modules\Sync\Domain\Exception\AlreadyWired;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| Wiring a store (sync.md §1.2, §1.8, §3; §9.3 #21): a Super Admin's alone; tested first, nothing saved
| when the test fails; the key and the secret encrypted, never shown or audited; the store wired that
| second - for Pricing and Inventory too - with a full read queued and Catalog told; changed the same
| way; unwired with the count first, its report closed and its waiting reads dropped.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    $this->superAdmin = Fx::staff(superAdmin: true);
    Fx::actAsStaff($this->superAdmin);
});

function syncSa(): string
{
    return Fx::storeId('sa');
}

function syncWire(string $address = 'https://odoo.test/api', string $key = 'key-1', string $secret = 'secret-1', string $provider = 'ODOO', array $settings = [], ?string $store = null): void
{
    app(WireStoreHandler::class)->handle(new WireStore($store ?? syncSa(), $provider, $address, $key, $secret, $settings));
}

function syncView(): ConnectionView
{
    return app(ViewConnectionHandler::class)->handle(new ViewConnection(syncSa()));
}

describe('wiring', function () {
    it('tests first, then keeps the key and the secret encrypted, wires the store for Pricing and Inventory, queues a full read and audits no secret', function () {
        $fake = FakeProvider::bind();

        syncWire();

        $row = DB::table('sync.connections')->where('store_id', syncSa())->first();
        $store = StoreId::fromString(syncSa());

        expect($fake->tested)->toHaveCount(1)
            ->and($fake->tested[0]->key)->toBe('key-1')
            ->and($row?->key_encrypted)->not->toContain('key-1')
            ->and(Crypt::decryptString((string) $row?->key_encrypted))->toBe('key-1')
            ->and(Crypt::decryptString((string) $row?->secret_encrypted))->toBe('secret-1')
            ->and($row?->state)->toBe('OK')
            ->and($row?->created_by)->toBe($this->superAdmin)
            ->and(app(PricingWiring::class)->isWired($store))->toBeTrue()
            ->and(app(InventoryWiring::class)->isWired($store))->toBeTrue()
            ->and(DB::table('sync.reads')->where('store_id', syncSa())->get(['kind', 'state', 'requested_by_type', 'requested_by_id'])->map(fn ($read) => (array) $read)->all())
            ->toBe([['kind' => 'NOW', 'state' => 'QUEUED', 'requested_by_type' => 'STAFF', 'requested_by_id' => $this->superAdmin]])
            ->and(Fx::audits('sync.connection.wired', syncSa()))->toBe(1);

        $audit = (string) DB::table('platform.audit_entries')->where('action', 'sync.connection.wired')->value('changes');

        expect($audit)->toContain('https://odoo.test/api')->not->toContain('key-1')->not->toContain('secret-1');
    });

    it('tells Catalog every size again as a wired store\'s: an ordinary size at 0 can be ordered', function () {
        $ready = Px::ready();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(syncSa(), $ready['product'], true)));
        $size = $ready['variants'][0];
        $told = fn (): mixed => DB::table('catalog.store_variant_facts')->where('store_id', syncSa())->where('variant_id', $size)->value('orderable');
        expect((bool) $told())->toBeFalse();
        FakeProvider::bind();

        syncWire();

        expect((bool) $told())->toBeTrue();
    });

    it('saves nothing when the test fails, and names the reason', function () {
        FakeProvider::bind('Timed out after 10 seconds');

        expect(fn () => syncWire())->toThrow(ConnectionFailed::class, 'Timed out after 10 seconds')
            ->and(DB::table('sync.connections')->count())->toBe(0)
            ->and(DB::table('sync.reads')->count())->toBe(0)
            ->and(app(PricingWiring::class)->isWired(StoreId::fromString(syncSa())))->toBeFalse();
    });

    it('cannot wire to Odoo before its adapter is built: it calls nothing and fails', function () {
        expect(fn () => syncWire())->toThrow(ConnectionFailed::class)
            ->and(DB::table('sync.connections')->count())->toBe(0);
    });

    it('refuses anyone but a Super Admin, before anything is looked up', function () {
        $fake = FakeProvider::bind();
        Fx::actAsStaff(Fx::staff());

        expect(fn () => syncWire())->toThrow(Unauthorized::class)
            ->and($fake->tested)->toBe([]);
    });

    it('names the field it refuses, and tests nothing', function (array $given, string $field) {
        $fake = FakeProvider::bind(fields: ['database']);

        try {
            syncWire(...$given);
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = $error->attribute;
        }

        expect($refused)->toBe($field)->and($fake->tested)->toBe([]);
    })->with([
        'a store that is not one' => [['store' => 'nope'], 'store'],
        'a store that does not exist' => [['store' => '01jbzzzzzzzzzzzzzzzzzzzzzz'], 'store'],
        'a provider we do not have' => [['provider' => 'SAP'], 'provider'],
        'an address not HTTPS' => [['address' => 'http://odoo.test/api'], 'address'],
        'an address with no host' => [['address' => 'https://'], 'address'],
        'an address too long' => [['address' => 'https://odoo.test/'.str_repeat('a', 500)], 'address'],
        'an empty key' => [['key' => '  '], 'key'],
        'an empty secret' => [['secret' => ''], 'secret'],
        'a key too long' => [['key' => str_repeat('k', 1001)], 'key'],
        'a field the provider does not ask for' => [['settings' => ['company' => '1']], 'provider'],
        'an empty field it asks for' => [['settings' => ['database' => ' ']], 'provider'],
    ]);

    it('takes the fields the provider asks for', function () {
        FakeProvider::bind(fields: ['database']);

        syncWire(settings: ['database' => 'touchwood']);

        expect(syncView()->settings)->toBe(['database' => 'touchwood']);
    });

    it('refuses a store already wired, without calling the provider', function () {
        $fake = FakeProvider::bind();
        syncWire();

        expect(fn () => syncWire())->toThrow(AlreadyWired::class)
            ->and($fake->tested)->toHaveCount(1)
            ->and(DB::table('sync.reads')->count())->toBe(1);
    });

    it('makes the provider\'s prices read-only in the panel at once', function () {
        $size = Px::ready()['variants'][0];
        FakeProvider::bind();
        syncWire();

        expect(fn () => Rx::set('sa', [$size => '10']))->toThrow(ProviderOwnsPrice::class);
    });
});

describe('changing the connection', function () {
    it('tests the new one first, keeps a key left empty, audits a replaced secret without it, and queues a full read', function () {
        $fake = FakeProvider::bind();
        syncWire();

        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo2.test/api', null, 'secret-2'));

        $row = DB::table('sync.connections')->where('store_id', syncSa())->first();
        $audit = (string) DB::table('platform.audit_entries')->where('action', 'sync.connection.changed')->value('changes');

        expect($fake->tested)->toHaveCount(2)
            ->and($fake->tested[1]->key)->toBe('key-1')
            ->and($fake->tested[1]->secret)->toBe('secret-2')
            ->and($row?->address)->toBe('https://odoo2.test/api')
            ->and(Crypt::decryptString((string) $row?->key_encrypted))->toBe('key-1')
            ->and(Crypt::decryptString((string) $row?->secret_encrypted))->toBe('secret-2')
            ->and(DB::table('sync.reads')->count())->toBe(2)
            ->and($audit)->toContain('odoo2.test')->toContain('replaced')->not->toContain('secret-2')->not->toContain('key-1');
    });

    it('changes nothing when the new one fails its test', function () {
        $fake = FakeProvider::bind();
        syncWire();
        $fake->failure = 'Refused: wrong key';

        expect(fn () => app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo2.test/api', 'key-2')))->toThrow(ConnectionFailed::class, 'Refused: wrong key')
            ->and(DB::table('sync.connections')->value('address'))->toBe('https://odoo.test/api')
            ->and(Crypt::decryptString((string) DB::table('sync.connections')->value('key_encrypted')))->toBe('key-1')
            ->and(Fx::audits('sync.connection.changed'))->toBe(0);
    });

    it('refuses a store not wired, and anyone but a Super Admin', function () {
        FakeProvider::bind();

        expect(fn () => app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api')))->toThrow(NotWired::class);

        Fx::actAsStaff(Fx::staff());

        expect(fn () => app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api')))->toThrow(Unauthorized::class);
    });
});

describe('unwiring (sync.md §1.8)', function () {
    it('shows the count first, then unwires: the key and secret gone, the report closed, the waiting reads dropped, the count audited', function () {
        $ready = Px::ready();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(syncSa(), $ready['product'], true)));
        FakeProvider::bind();
        syncWire();
        DB::table('sync.report_entries')->insert(['store_id' => syncSa(), 'kind' => 'UNKNOWN_CODE', 'code' => '123', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);

        expect(syncView()->stopsWhenUnwired)->toBe(1);

        $stopped = app(UnwireStoreHandler::class)->handle(new UnwireStore(syncSa()));

        expect($stopped)->toBe(1)
            ->and(DB::table('sync.connections')->count())->toBe(0)
            ->and(app(InventoryWiring::class)->isWired(StoreId::fromString(syncSa())))->toBeFalse()
            ->and(DB::table('sync.report_entries')->value('closed_by'))->toBe('UNWIRED')
            ->and(DB::table('sync.reads')->value('state'))->toBe('FAILED')
            ->and(DB::table('sync.reads')->value('error'))->toBe('The store was unwired.')
            ->and(Fx::audits('sync.connection.unwired', syncSa()))->toBe(1)
            ->and((string) DB::table('platform.audit_entries')->where('action', 'sync.connection.unwired')->value('changes'))->toContain('stopped_orderable');
    });

    it('refuses a store not wired, and anyone but a Super Admin', function () {
        expect(fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore(syncSa())))->toThrow(NotWired::class);

        Fx::actAsStaff(Fx::staff());

        expect(fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore(syncSa())))->toThrow(Unauthorized::class);
    });
});

describe('the Provider page', function () {
    it('shows a store not wired, and a wired one without its key or secret', function () {
        expect(syncView()->provider)->toBeNull()->and(syncView()->keySet)->toBeFalse();

        FakeProvider::bind();
        syncWire();
        $view = syncView();

        expect($view->provider)->toBe('ODOO')
            ->and($view->address)->toBe('https://odoo.test/api')
            ->and($view->keySet)->toBeTrue()
            ->and($view->setAt)->not->toBeNull()
            ->and($view->state)->toBe('OK')
            ->and(json_encode($view))->not->toContain('key-1')->not->toContain('secret-1');
    });

    it('is for Super Admins only', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => syncView())->toThrow(Unauthorized::class);
    });
});
