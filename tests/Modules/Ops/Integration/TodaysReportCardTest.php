<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Presentation\Home\TodaysReportCard;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Today's Report in the home's greeting row (dashboard.md §2.1): the store's daily report for its makers, the
| all-stores report for a Super Admin alone, as on Daily Reports - made by the Daily Reports page's own action.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->setLocale('en');
});

it('offers the chosen store\'s report through the Daily Reports page\'s own action', function () {
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['eg']));

    $action = app(TodaysReportCard::class)->data(HomeScope::store(StoreId::fromString(Fx::storeId('eg'))))?->action;

    expect($action?->href)->toBe('/admin/daily-reports')
        ->and($action?->fields)->toBe(['store' => Fx::storeId('eg'), 'day' => ''])
        ->and($action?->label)->toBe("Today's Report (PDF)")
        ->and(app(TodaysReportCard::class)->data(HomeScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBeNull();
});

it('offers the all-stores report to a Super Admin only, even to a maker of every store\'s', function () {
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['*']));
    expect(app(TodaysReportCard::class)->data(HomeScope::allStores()))->toBeNull();

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(app(TodaysReportCard::class)->data(HomeScope::allStores())?->action?->fields)->toBe(['store' => '', 'day' => '']);
});
