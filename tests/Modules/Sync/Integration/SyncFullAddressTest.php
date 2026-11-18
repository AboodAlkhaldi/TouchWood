<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\Query\ViewConnection\ConnectionView;
use Modules\Sync\Application\Query\ViewConnection\ViewConnection;
use Modules\Sync\Application\Query\ViewConnection\ViewConnectionHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| flags-2026-11.md S6 (owner, 2026-11-18): the Provider page takes the API's full address, and the secret, which
| only signs the provider's notices, is optional. A store with no secret takes no notices, so the page shows no
| Notice Address; the migration gives Odoo's path to an address saved as the account's alone.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Queue::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    FakeProvider::bind();
});

function fullAddressView(): ConnectionView
{
    return app(ViewConnectionHandler::class)->handle(new ViewConnection(Fx::storeId('sa')));
}

it('says whether a webhook secret is stored: the page shows the Notice Address only with one (its props: SyncScreensTest)', function () {
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', ''));

    expect(fullAddressView()->keySet)->toBeTrue()
        ->and(fullAddressView()->secretSet)->toBeFalse();

    DB::table('sync.connections')->delete();
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));

    expect(fullAddressView()->secretSet)->toBeTrue();
});

it('gives Odoo\'s path to an Odoo address saved as the account\'s alone, and leaves a full one as it is', function () {
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('ae'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('eg'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
    // As the old rule saved it: the account's address alone, with and without a closing slash.
    DB::table('sync.connections')->where('store_id', Fx::storeId('sa'))->update(['address' => 'https://example.odoo.com/']);
    DB::table('sync.connections')->where('store_id', Fx::storeId('eg'))->update(['address' => 'https://eg.example.com:8443']);

    $migration = require base_path('src/Modules/Sync/Infrastructure/Persistence/Migrations/2026_11_18_100000_take_the_odoo_api_full_address.php');
    $migration->up();

    expect(DB::table('sync.connections')->where('store_id', Fx::storeId('sa'))->value('address'))->toBe('https://example.odoo.com/json/2/product.product/read')
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('eg'))->value('address'))->toBe('https://eg.example.com:8443/json/2/product.product/read')
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->value('address'))->toBe('https://odoo.test/api');
});
