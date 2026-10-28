<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
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
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;
use Shared\Application\ActorContext;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
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
    // Wiring queues a read; reading is SyncReadsTest's.
    Queue::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/** Whoever acts now: the Super Admin each test starts as. */
function syncActor(): string
{
    return (string) app(ActorContext::class)->current()->id;
}

function syncSa(): string
{
    return Fx::storeId('sa');
}

/**
 * @param  array<string, mixed>  $settings
 */
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
            ->and(str_contains((string) $row?->key_encrypted, 'key-1'))->toBeFalse()
            ->and(Crypt::decryptString((string) $row?->key_encrypted))->toBe('key-1')
            ->and(Crypt::decryptString((string) $row?->secret_encrypted))->toBe('secret-1')
            ->and($row?->state)->toBe('OK')
            ->and($row?->created_by)->toBe(syncActor())
            ->and(app(PricingWiring::class)->isWired($store))->toBeTrue()
            ->and(app(InventoryWiring::class)->isWired($store))->toBeTrue()
            ->and(DB::table('sync.reads')->where('store_id', syncSa())->get(['kind', 'state', 'requested_by_type', 'requested_by_id'])->map(fn ($read) => (array) $read)->all())
            ->toBe([['kind' => 'NOW', 'state' => 'QUEUED', 'requested_by_type' => 'STAFF', 'requested_by_id' => syncActor()]])
            ->and(Fx::audits('sync.connection.wired', syncSa()))->toBe(1);

        Queue::assertPushed(ReadStoreJob::class, fn (ReadStoreJob $job): bool => $job->readId === DB::table('sync.reads')->value('id'));

        $audit = (string) DB::table('platform.audit_entries')->where('action', 'sync.connection.wired')->value('changes');

        expect($audit)->toContain('https://odoo.test/api')
            ->and(str_contains($audit, 'key-1') || str_contains($audit, 'secret-1'))->toBeFalse();
    });

    it('tells Catalog every size again as a wired store\'s: stock-dependent by default, by its stock until the first read', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(syncSa(), $ready['product'], true)));
        [$stocked, $none] = $ready['variants'];
        Ix::count('sa', [$stocked => 3]);
        $told = fn (string $size): mixed => DB::table('catalog.store_variant_facts')->where('store_id', syncSa())->where('variant_id', $size)->value('orderable');
        DB::table('catalog.store_variant_facts')->where('store_id', syncSa())->update(['orderable' => null]);
        FakeProvider::bind();

        syncWire();

        // Every size counts on stock by default (inventory.md §9.2 #24): no stock row, 0, not orderable.
        expect($told($stocked))->toBeTrue()
            ->and($told($none))->toBeFalse();

        // One staff switch off is an ordinary size: orderable at 0.
        Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(syncSa(), [$none], false)));

        expect($told($none))->toBeTrue();
    });

    it('saves nothing when the test fails, and names the reason', function () {
        FakeProvider::bind('Timed out after 10 seconds');

        expect(fn () => syncWire())->toThrow(ConnectionFailed::class, 'Timed out after 10 seconds')
            ->and(DB::table('sync.connections')->count())->toBe(0)
            ->and(DB::table('sync.reads')->count())->toBe(0)
            ->and(app(PricingWiring::class)->isWired(StoreId::fromString(syncSa())))->toBeFalse();
    });

    it('wires to Odoo only when its adapter\'s test call works: a refused token saves nothing (SyncOdooAdapterTest)', function () {
        Http::fake(['odoo.test/*' => Http::response(['error' => 'bad token'], 401)]);

        expect(fn () => syncWire('https://odoo.test/touchwood/v1'))->toThrow(ConnectionFailed::class, 'The provider could not be read: Odoo refused the token (401).')
            ->and(DB::table('sync.connections')->count())->toBe(0);
        Http::assertSentCount(1);
    });

    it('refuses anyone but a Super Admin, before anything is looked up', function () {
        $fake = FakeProvider::bind();
        Fx::actAsStaff(Fx::staff());

        expect(fn () => syncWire())->toThrow(Unauthorized::class)
            ->and(fn () => syncWire(store: '01jbzzzzzzzzzzzzzzzzzzzzzz'))->toThrow(Unauthorized::class)
            ->and($fake->tested)->toBe([]);
    });

    it('takes the scheme in any case, and keeps it as the table does', function () {
        FakeProvider::bind();

        syncWire(address: 'HTTPS://Odoo.test/api');

        expect(DB::table('sync.connections')->value('address'))->toBe('https://Odoo.test/api');
    });

    it('names the field it refuses, and tests nothing', function (array $given, string $field) {
        $fake = FakeProvider::bind(fields: ['database']);

        try {
            syncWire(...['settings' => ['database' => 'tw'], ...$given]);
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = $error->attribute.($error->setting === null ? '' : '.'.$error->setting);
        }

        expect($refused)->toBe($field)->and($fake->tested)->toBe([]);
    })->with([
        'a store that is not one' => [['store' => 'nope'], 'store'],
        'a store that does not exist' => [['store' => '01jbzzzzzzzzzzzzzzzzzzzzzz'], 'store'],
        'a provider we do not have' => [['provider' => 'SAP'], 'provider'],
        'an address not HTTPS' => [['address' => 'http://odoo.test/api'], 'address'],
        'an address with no host' => [['address' => 'https://'], 'address'],
        'an address too long' => [['address' => 'https://odoo.test/'.str_repeat('a', 500)], 'address'],
        'a name and password in the address' => [['address' => 'https://admin:pass@odoo.test/api'], 'address'],
        'an empty key' => [['key' => '  '], 'key'],
        'an empty secret' => [['secret' => ''], 'secret'],
        'a key too long' => [['key' => str_repeat('k', 1001)], 'key'],
        // Each of the provider's settings refused by its own name (Q34).
        'a field the provider does not ask for' => [['settings' => ['company' => '1']], 'settings.company'],
        'an empty field it asks for' => [['settings' => ['database' => ' ']], 'settings.database'],
        'a field it asks for left out' => [['settings' => []], 'settings.database'],
        'a field that is not text' => [['settings' => ['database' => 7]], 'settings.database'],
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
    it('tests the new one first, keeps a key left empty at the same address, audits a replaced secret without it, and queues a full read', function () {
        $fake = FakeProvider::bind();
        syncWire();

        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api', null, 'secret-2'));

        $row = DB::table('sync.connections')->where('store_id', syncSa())->first();
        $audit = (string) DB::table('platform.audit_entries')->where('action', 'sync.connection.changed')->value('changes');

        expect($fake->tested)->toHaveCount(2)
            ->and($fake->tested[1]->key)->toBe('key-1')
            ->and($fake->tested[1]->secret)->toBe('secret-2')
            ->and($row?->address)->toBe('https://odoo.test/api')
            ->and(Crypt::decryptString((string) $row?->key_encrypted))->toBe('key-1')
            ->and(Crypt::decryptString((string) $row?->secret_encrypted))->toBe('secret-2')
            // The full read wiring queued still waits: asking again queues no second (§1.3).
            ->and(DB::table('sync.reads')->count())->toBe(1)
            ->and($audit)->toContain('replaced')
            ->and(str_contains($audit, 'secret-2') || str_contains($audit, 'key-1'))->toBeFalse();
    });

    it('changes nothing when the new one fails its test', function () {
        $fake = FakeProvider::bind();
        syncWire();
        $fake->failure = 'Refused: wrong key';

        expect(fn () => app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo2.test/api', 'key-2', 'secret-2')))->toThrow(ConnectionFailed::class, 'Refused: wrong key')
            ->and(DB::table('sync.connections')->value('address'))->toBe('https://odoo.test/api')
            ->and(Crypt::decryptString((string) DB::table('sync.connections')->value('key_encrypted')))->toBe('key-1')
            ->and(Fx::audits('sync.connection.changed'))->toBe(0);
    });

    it('tests again what is saved when another change landed while it was tested, and keeps that change\'s key', function () {
        $fake = FakeProvider::bind();
        syncWire();
        // While the provider answers, someone else sets a new key.
        $fake->whileTesting = fn () => DB::table('sync.connections')->update(['key_encrypted' => Crypt::encryptString('key-other')]);

        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api'));

        expect($fake->tested)->toHaveCount(3)
            ->and($fake->tested[2]->key)->toBe('key-other')
            ->and(Crypt::decryptString((string) DB::table('sync.connections')->value('key_encrypted')))->toBe('key-other');
    });

    it('takes a key and a secret typed again for a new address: either left empty refused under its own box, nothing tested (owner, 2026-10-20)', function (?string $key, ?string $secret, string $field, string $rule) {
        $fake = FakeProvider::bind();
        syncWire();

        try {
            app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo2.test/api', $key, $secret));
            $refused = null;
        } catch (InvalidSyncInput $error) {
            $refused = $error->context();
        }

        expect($refused)->toBe(['attribute' => $field, 'rule' => $rule])
            ->and($fake->tested)->toHaveCount(1)
            ->and(DB::table('sync.connections')->value('address'))->toBe('https://odoo.test/api');
    })->with([
        'both empty' => [null, null, 'key', 'key_again'],
        'the key empty' => ['', 'secret-2', 'key', 'key_again'],
        'the secret empty' => ['key-2', '', 'secret', 'secret_again'],
    ]);

    it('refuses a change whose address another change moved while it was tested, with the stored key never saved there', function () {
        $fake = FakeProvider::bind();
        syncWire();
        $fake->whileTesting = fn () => DB::table('sync.connections')->update(['address' => 'https://odoo3.test/api']);

        expect(fn () => app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api')))->toThrow(InvalidSyncInput::class)
            ->and(DB::table('sync.connections')->value('address'))->toBe('https://odoo3.test/api')
            ->and($fake->tested)->toHaveCount(2);
    });

    it('leaves the connection\'s state and last read to the reads', function () {
        $fake = FakeProvider::bind();
        syncWire();
        $fake->whileTesting = fn () => DB::table('sync.connections')->update(['state' => 'FAILING', 'last_error' => 'Timed out']);

        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api'));

        expect(DB::table('sync.connections')->value('state'))->toBe('FAILING')
            ->and(DB::table('sync.connections')->value('last_error'))->toBe('Timed out');
    });

    it('audits a field changed, and not one only stored in another order', function () {
        FakeProvider::bind(fields: ['database', 'company']);
        syncWire(settings: ['database' => 'tw', 'company' => '1']);

        // The order it was saved in; the database gives it back in its own (shorter keys first).
        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api', settings: ['database' => 'tw', 'company' => '1']));
        app(ChangeConnectionHandler::class)->handle(new ChangeConnection(syncSa(), 'ODOO', 'https://odoo.test/api', settings: ['company' => '2', 'database' => 'tw']));

        $changes = DB::table('platform.audit_entries')->where('action', 'sync.connection.changed')->orderBy('id')->pluck('changes')->all();

        expect((string) $changes[0])->not->toContain('settings')
            ->and((string) $changes[1])->toContain('settings');
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

        // Stock-dependent by default and at 0, it is not orderable now, so nothing would stop.
        expect(syncView()->stopsWhenUnwired)->toBe(0);

        // Switched off by staff (an ordinary size), it is orderable at 0 - and stops once unwired.
        Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(syncSa(), [$ready['variants'][0]], false)));

        expect(syncView()->stopsWhenUnwired)->toBe(1);

        $stopped = app(UnwireStoreHandler::class)->handle(new UnwireStore(syncSa()));

        expect($stopped)->toBe(1)
            ->and(DB::table('sync.connections')->count())->toBe(0)
            ->and(app(InventoryWiring::class)->isWired(StoreId::fromString(syncSa())))->toBeFalse()
            ->and(DB::table('sync.report_entries')->value('closed_by'))->toBe('UNWIRED')
            ->and(DB::table('sync.reads')->value('state'))->toBe('FAILED')
            ->and(DB::table('sync.reads')->value('error'))->toBe('UNWIRED')
            ->and(Fx::audits('sync.connection.unwired', syncSa()))->toBe(1)
            ->and((string) DB::table('platform.audit_entries')->where('action', 'sync.connection.unwired')->value('changes'))->toContain('stopped_orderable');
    });

    it('fails a read running when the store is unwired: it stops before its next batch', function () {
        FakeProvider::bind();
        syncWire();
        DB::table('sync.reads')->update(['state' => 'RUNNING', 'started_at' => now()]);

        app(UnwireStoreHandler::class)->handle(new UnwireStore(syncSa()));

        expect(DB::table('sync.reads')->value('state'))->toBe('FAILED');
    });

    it('leaves every other store as it is', function () {
        FakeProvider::bind();
        syncWire();
        syncWire(store: Fx::storeId('ae'));
        DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('ae'), 'kind' => 'UNKNOWN_CODE', 'code' => '9', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);

        app(UnwireStoreHandler::class)->handle(new UnwireStore(syncSa()));

        expect(DB::table('sync.connections')->pluck('store_id')->all())->toBe([Fx::storeId('ae')])
            ->and(DB::table('sync.report_entries')->whereNull('closed_at')->count())->toBe(1)
            ->and(DB::table('sync.reads')->where('store_id', Fx::storeId('ae'))->value('state'))->toBe('QUEUED');
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
            ->and($view->changedAt)->not->toBeNull()
            ->and($view->state)->toBe('WORKING')
            ->and(str_contains((string) json_encode($view), 'key-1') || str_contains((string) json_encode($view), 'secret-1'))->toBeFalse();
    });

    it('is for Super Admins only', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => syncView())->toThrow(Unauthorized::class);
    });
});
