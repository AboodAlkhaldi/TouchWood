<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Infrastructure\Queue\PruneSearchLogJob;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;

use function Pest\Laravel\seed;

/*
| Catalog's queued work run as the queue runs it (review of step 7): dispatched on the "sync" queue,
| the test supplying no actor — Platform's job switch makes it the system's, on behalf of whoever
| queued it (platform.md §3), as on a worker.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
});

afterEach(function () {
    Ix::cleanUp();
    CarbonImmutable::setTestNow();
});

it('brings an import in once the confirm queues it, as the system on the Super Admin\'s behalf', function () {
    $staff = Fx::staff(superAdmin: true);
    Fx::actAsStaff($staff);
    $import = Ix::uploadProducts([Ix::product('1')]);

    app(BringInImportHandler::class)->handle(new BringInImport($import));

    expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN')
        ->and((array) DB::table('platform.audit_entries')->where('action', 'catalog.import.brought_in')->where('subject_id', $import)->first(['actor_type', 'actor_id', 'requested_by_type', 'requested_by_id']))
        ->toBe(['actor_type' => 'SYSTEM', 'actor_id' => null, 'requested_by_type' => 'STAFF', 'requested_by_id' => $staff]);
});

it('prunes the search log as the system, queued with no one asking', function () {
    CarbonImmutable::setTestNow('2027-06-15 01:00:00');

    foreach (['2026-06-14 23:59:59', '2027-06-01 10:00:00'] as $at) {
        DB::table('catalog.search_log')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'query' => 'drawer', 'results' => 0, 'searched_at' => $at]);
    }

    PruneSearchLogJob::dispatch();

    expect(DB::table('catalog.search_log')->count())->toBe(1);
});
