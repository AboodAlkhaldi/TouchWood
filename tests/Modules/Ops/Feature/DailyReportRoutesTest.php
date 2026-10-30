<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\DailyReportFixtures as Dx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The daily reports over HTTP (ops.md §1.11, §4): make one, list them, download one's PDF, and the
| reader's own emails of it - for a signed-in staff member with ops.report.daily; nobody else.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    Storage::fake(LocalReportFiles::DISK);
    Dx::fakeRenderer();
});

function dailyReportRoutesStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.9.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('makes a store\'s report, lists it and downloads its PDF, and keeps the reader\'s own email', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    $browser = dailyReportRoutesStaff($staff);

    $made = $browser->post('/admin/daily-reports', ['store' => Fx::storeId('sa')])->assertCreated()->json();
    expect($made)->toMatchArray(['storeCode' => 'sa', 'storeName' => 'Saudi Arabia', 'pastDay' => false, 'emailedAt' => null, 'language' => 'en'])
        ->and($made['downloadUrl'])->toEndWith("/admin/daily-reports/{$made['id']}/pdf");

    $list = $browser->get('/admin/daily-reports', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect(array_column($list['reports'], 'id'))->toBe([$made['id']])
        ->and($list['next'])->toBeNull();

    $pdf = $browser->get("/admin/daily-reports/{$made['id']}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and((string) $pdf->headers->get('Content-Disposition'))->toContain('daily-report-sa-'.$made['day'].'.pdf')
        ->and($pdf->getContent())->toStartWith('%PDF');

    $emails = $browser->post('/admin/daily-reports/emails', ['store' => Fx::storeId('sa'), 'on' => '1', 'sendAt' => '07:30'])->assertOk()->json();
    expect($emails)->toBe([['storeId' => Fx::storeId('sa'), 'storeCode' => 'sa', 'storeName' => 'Saudi Arabia', 'on' => true, 'sendAt' => '07:30', 'timezone' => 'Asia/Riyadh']])
        ->and($browser->get('/admin/daily-reports/emails', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json())->toBe($emails);
});

it('opens no daily report to anyone signed out, or to staff without ops.report.daily', function () {
    get('/admin/daily-reports', ['Accept' => 'application/json'])->assertUnauthorized();

    $browser = dailyReportRoutesStaff(Fx::staffWith(['ops.report.view'], ['sa']));
    $browser->get('/admin/daily-reports', ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
    $browser->get('/admin/daily-reports/01jbxqz2y8k4n6p9r3t5v7w1xa/pdf', ['HTTP_ACCEPT' => 'application/json'])->assertNotFound();
});
