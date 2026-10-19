<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| A bad value's entry says which value was refused (sync.md §1.5 #2; the review of stage 5's screens step 7):
| `refused` is the price, the stock or both, so the Sync Report never guesses - a whole stock number past what
| a size can hold is the stock's, never the price's.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-19 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('names the refused value in each bad value: the price, the stock, or both', function () {
    $ready = Px::ready(['60 cm', '80 cm', '100 cm', '120 cm']);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    [$price, $fraction, $both, $tooMany] = $ready['variants'];
    $code = static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code');
    FakeProvider::bound()->values = [
        new ProviderValue($code($price), '0', 2),
        new ProviderValue($code($fraction), '10.00', '2.5'),
        new ProviderValue($code($both), '0', -3),
        new ProviderValue($code($tooMany), '10.00', 3_000_000_000),
    ];
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');

    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    $refused = static fn (string $size): mixed => json_decode((string) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->where('variant_id', $size)->value('detail'), true)['refused'] ?? null;

    expect([$refused($price), $refused($fraction), $refused($both), $refused($tooMany)])->toBe(['price', 'stock', 'both', 'stock']);
});
