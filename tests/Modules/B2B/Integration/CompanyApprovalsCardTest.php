<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Presentation\Home\CompanyApprovalsCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->setLocale('en');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/*
| The admin home's Company Approvals card (b2b.md amendment 27; the owner, 2026-10-05): how many
| wait and who, the oldest first, with their store and the way to each; the companies by status;
| links to the list for the same stores; nothing for a scope wider than the reader's stores.
*/

function approvalsData(HomeScope $scope): HomeCardData
{
    $data = app(CompanyApprovalsCard::class)->data($scope);
    expect($data)->not->toBeNull();
    assert($data instanceof HomeCardData);

    return $data;
}

function approvalsStore(string $code): HomeScope
{
    return HomeScope::store(StoreId::fromString(Fx::storeId($code)));
}

/**
 * @return array<string, int> label => value
 */
function approvalsFigures(HomeCardData $data): array
{
    $figures = [];

    foreach ($data->figures as $figure) {
        $figures[$figure->label] = $figure->value;
    }

    return $figures;
}

it('counts the companies of the scope by status, and names those waiting, the oldest first, with their store', function () {
    CarbonImmutable::setTestNow('2026-10-01 09:00:00');
    [$older] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    CarbonImmutable::setTestNow('2026-10-02 09:00:00');
    [$newer] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount(), 'eg');
    B2BFixtures::approved(B2BFixtures::verifiedCompanyAccount());
    CarbonImmutable::setTestNow();

    $ksa = approvalsData(approvalsStore('sa'));
    $all = approvalsData(HomeScope::allStores());

    expect(approvalsFigures($ksa))->toBe(['waiting' => 1, 'approved' => 1, 'not_approved' => 0, 'suspended' => 0])
        ->and(array_map(static fn (HomeRow $row): ?string => $row->href, $ksa->rows))->toBe(["/admin/companies/{$older->id()}"])
        ->and(approvalsFigures($all))->toBe(['waiting' => 2, 'approved' => 1, 'not_approved' => 0, 'suspended' => 0])
        // The oldest waiting first, each with its store in the reader's language.
        ->and(array_map(static fn (HomeRow $row): array => [$row->href, $row->detail], $all->rows))->toBe([
            ["/admin/companies/{$older->id()}", 'Saudi Arabia'],
            ["/admin/companies/{$newer->id()}", 'Egypt'],
        ])
        ->and($all->rowsLabel)->toBe('waiting_list')
        // The card and the figure open the list on those waiting.
        ->and($all->href)->toBe('/admin/companies?status=PENDING')
        ->and(array_map(static fn (HomeFigure $figure): ?string => $figure->tone, $all->figures))->toBe(['amber', null, null, null]);
});

it('links This Store\'s figures to the list of that store, so the two agree', function () {
    $store = Fx::storeId('sa');
    $ksa = approvalsData(approvalsStore('sa'));

    expect($ksa->href)->toBe("/admin/companies?status=PENDING&store={$store}")
        ->and(array_map(static fn (HomeFigure $figure): ?string => $figure->href, $ksa->figures))->toBe([
            "/admin/companies?status=PENDING&store={$store}",
            "/admin/companies?status=APPROVED&store={$store}",
            "/admin/companies?status=REJECTED&store={$store}",
            "/admin/companies?status=SUSPENDED&store={$store}",
        ]);
});

it('names the five waiting the longest, and counts them all', function () {
    foreach (range(1, 6) as $day) {
        CarbonImmutable::setTestNow("2026-10-0{$day} 09:00:00");
        B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    }
    CarbonImmutable::setTestNow();

    $data = approvalsData(HomeScope::allStores());

    expect($data->rows)->toHaveCount(CompanyApprovalsCard::NAMED)
        ->and(approvalsFigures($data)['waiting'])->toBe(6);
});

it('shows nothing for a scope wider than the reader\'s stores, as the list\'s handler refuses it', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount(), 'eg');
    Fx::actAsStaff(Fx::staffWith([B2BPermissions::COMPANY_VIEW], ['sa']));
    $card = app(CompanyApprovalsCard::class);

    expect($card->data(HomeScope::allStores()))->toBeNull()
        ->and($card->data(approvalsStore('eg')))->toBeNull()
        ->and(approvalsFigures(approvalsData(approvalsStore('sa')))['waiting'])->toBe(0);
});

it('says nothing waits when nothing does, and names nobody', function () {
    B2BFixtures::approved(B2BFixtures::verifiedCompanyAccount());

    $data = approvalsData(HomeScope::allStores());

    expect(approvalsFigures($data))->toBe(['waiting' => 0, 'approved' => 1, 'not_approved' => 0, 'suspended' => 0])
        ->and($data->rows)->toBe([])
        ->and($data->rowsLabel)->toBeNull()
        ->and($data->figures[0]->tone)->toBeNull();
});
