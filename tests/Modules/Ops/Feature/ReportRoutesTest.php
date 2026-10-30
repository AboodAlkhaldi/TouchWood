<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Search\SearchLog;
use Modules\Ops\Application\OpsPermissions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The reports over HTTP (ops.md §1.10, §4): a report's preview as JSON and its Excel file, for a signed-in
| staff member with ops.report.view in the store; nobody else.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function reportRoutesStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('previews a report as JSON and downloads it as an Excel file', function () {
    app(SearchLog::class)->record(Fx::storeId('sa'), 'en', 'oak door', 0);
    $browser = reportRoutesStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));
    $query = '?store='.Fx::storeId('sa').'&from=2026-01-01&to=2026-12-31';

    $preview = $browser->get('/admin/reports/searches'.$query, ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect($preview)->toMatchArray([
        'report' => 'searches',
        'locale' => 'en',
        'rightToLeft' => false,
        'title' => 'Searches',
        'subtitle' => 'Saudi Arabia · 2026-01-01 to 2026-12-31',
        'filename' => 'searches-sa-2026-01-01-2026-12-31.xlsx',
        'downloadUrl' => '/admin/reports/searches/download'.$query,
    ])
        ->and($preview['sheets'][0]['columns'][2])->toBe(['key' => 'phrase', 'label' => 'Search', 'type' => 'text'])
        ->and($preview['sheets'][0]['more'])->toBeFalse();

    $download = $browser->get($preview['downloadUrl'])->assertOk();
    $file = $download->baseResponse;
    $path = $file instanceof BinaryFileResponse ? $file->getFile()->getPathname() : throw new LogicException('Not a file.');

    expect($download->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and((string) $download->headers->get('Content-Disposition'))->toContain('searches-sa-2026-01-01-2026-12-31.xlsx')
        ->and(substr((string) file_get_contents($path), 0, 2))->toBe('PK');
    @unlink($path);
});

it('opens no report to anyone signed out, to staff without ops.report.view, or for a store they do not hold', function () {
    get('/admin/reports/revenue?store=all&from=2026-10-01&to=2026-10-01', ['Accept' => 'application/json'])->assertUnauthorized();

    $browser = reportRoutesStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']));
    $browser->get('/admin/reports/revenue?store='.Fx::storeId('sa').'&from=2026-10-01&to=2026-10-01', ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
    $browser->get('/admin/reports/revenue/download?store='.Fx::storeId('sa').'&from=2026-10-01&to=2026-10-01', ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();

    $reader = reportRoutesStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));
    $reader->get('/admin/reports/revenue?store='.Fx::storeId('ae').'&from=2026-10-01&to=2026-10-01', ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
    $reader->get('/admin/reports/revenue?store=all&from=2026-10-01&to=2026-10-01', ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
    $reader->get('/admin/reports/revenue?store='.Fx::storeId('sa').'&from=2026-10-02&to=2026-10-01', ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable();
    $reader->get('/admin/reports/revenue', ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable();
});
