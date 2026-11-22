<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Enums\HomePeriod;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Presentation\Home\SyncProblemsCard;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Needs Attention's Sync Problems (dashboard.md §2 J, D6): the Sync Report's entries open now, and those opened in the
| period (readsAndProblemsPerDay), for the report's readers alone.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function syncProblemsEntry(string $storeCode, string $openedAt, bool $closed = false): void
{
    DB::table('sync.report_entries')->insert([
        'store_id' => Fx::storeId($storeCode), 'kind' => 'UNKNOWN_CODE', 'code' => 'ODOO-'.random_int(1000, 999999), 'detail' => '{}', 'opened_at' => $openedAt, 'seen_at' => $openedAt,
        'closed_at' => $closed ? CarbonImmutable::now() : null, 'closed_by' => $closed ? 'READ' : null,
    ]);
}

it('counts the entries open now and those opened in the period, in the reader\'s store alone', function () {
    syncProblemsEntry('sa', '2026-11-02 09:00:00+00');
    syncProblemsEntry('sa', '2026-10-15 09:00:00+00');
    syncProblemsEntry('sa', '2026-11-01 09:00:00+00', closed: true);
    syncProblemsEntry('eg', '2026-11-02 09:00:00+00');
    Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['sa']));

    $month = app(SyncProblemsCard::class)->data(HomeScope::store(StoreId::fromString(Fx::storeId('sa')), HomePeriod::Month));
    $today = app(SyncProblemsCard::class)->data(HomeScope::store(StoreId::fromString(Fx::storeId('sa')), HomePeriod::Today));

    expect(array_map(static fn ($figure): array => [$figure->label, $figure->value], $month->figures ?? []))->toBe([['open', 2], ['opened', 2]])
        ->and($month?->href)->toBe('/admin/sync?store=sa')
        ->and($today?->figures[1]->value)->toBe(1)
        ->and(app(SyncProblemsCard::class)->data(HomeScope::store(StoreId::fromString(Fx::storeId('eg')))))->toBeNull()
        ->and(app(SyncProblemsCard::class)->data(HomeScope::allStores()))->toBeNull();
});

it('says nothing with no problem open or opened', function () {
    Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['sa']));

    expect(app(SyncProblemsCard::class)->data(HomeScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBeNull();
});
