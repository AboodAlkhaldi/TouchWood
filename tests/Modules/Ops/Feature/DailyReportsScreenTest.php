<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\DailyReportFixtures as Dx;

use function Pest\Laravel\seed;

/*
| O9, Daily Reports as a page (ops.md §4.15): a plain visit gets the page and JSON stays for whoever asks; a change the
| page sends goes back with its words. DailyReportRoutesTest keeps the JSON answers.
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

function dailyReportsScreenStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.4.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, string> */
function dailyReportsScreenInertia(): array
{
    return ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_REFERER' => url('/admin/daily-reports')];
}

/**
 * The words a form went back with.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
function dailyReportsScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

it('gives a plain visit the page, with the stores and their zones, and JSON to whoever asks', function () {
    $browser = dailyReportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa', 'ae'], RoleLevel::Admin));

    $browser->get('/admin/daily-reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/DailyReports')
        ->has('reports', 0)
        ->where('next', null)
        ->has('emails', 0)
        ->has('stores', 2)
        ->has('emailStores', 2)
        ->where('allStores', false)
        ->where('zones.'.Fx::storeId('sa'), 'Asia/Riyadh')
        ->where('translations', fn (Collection $words): bool => $words->has('ops::admin_daily_reports.title') && $words->has('ops::errors.daily_email_at_midnight.detail')));

    expect(array_keys($browser->get('/admin/daily-reports', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json()))->toBe(['reports', 'next']);
});

it('offers every store\'s report to a Super Admin alone, read in the base store\'s time', function () {
    dailyReportsScreenStaff(Fx::staff(superAdmin: true))->get('/admin/daily-reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('allStores', true)
        ->where('zones.', 'Asia/Riyadh'));
});

it('refuses the page to someone without ops.report.daily', function () {
    dailyReportsScreenStaff(Fx::staffWith(['ops.report.view'], ['sa']))->get('/admin/daily-reports')->assertForbidden();
});

it('makes a report from the page and goes back with its toast, the new row on top', function () {
    $browser = dailyReportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin));

    $answer = $browser->post('/admin/daily-reports', ['store' => Fx::storeId('sa'), 'day' => ''], dailyReportsScreenInertia())->assertRedirect('/admin/daily-reports');
    expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('ops::admin_daily_reports.make.toast'));

    $browser->get('/admin/daily-reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 1)->where('reports.0.storeCode', 'sa'));
});

it('says a refused day or store in the form\'s error, above the form', function () {
    $browser = dailyReportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin));
    $from = dailyReportsScreenInertia();

    expect(array_keys(dailyReportsScreenErrors($browser->post('/admin/daily-reports', ['store' => Fx::storeId('sa'), 'day' => '2999-01-01'], $from))))->toBe(['form'])
        ->and(array_keys(dailyReportsScreenErrors($browser->post('/admin/daily-reports', ['store' => Fx::storeId('ae'), 'day' => ''], $from))))->toContain('form');
});

it('saves the email from the page, says 00:00 in its own words on the row, and keeps JSON for what no page sent', function () {
    $browser = dailyReportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin));
    $from = dailyReportsScreenInertia();
    $store = Fx::storeId('sa');

    $browser->post('/admin/daily-reports/emails', ['store' => $store, 'on' => true, 'sendAt' => '07:30'], $from)->assertRedirect('/admin/daily-reports');
    expect(DB::table('ops.daily_report_settings')->where('store_id', $store)->value('is_on'))->toBeTrue();

    $midnight = dailyReportsScreenErrors($browser->post('/admin/daily-reports/emails', ['store' => $store, 'on' => true, 'sendAt' => '00:00'], $from));
    expect($midnight['email'][0])->toBe(__('ops::errors.daily_email_at_midnight.detail'))
        ->and(array_keys($midnight))->toBe(['email']);

    $browser->post('/admin/daily-reports/emails', ['store' => $store, 'on' => '0', 'sendAt' => '08:00'])->assertOk()->assertJsonPath('0.sendAt', '08:00');
});

it('keeps a switched-off store\'s row for whoever set up its email, marked off, and offers it nothing else', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa', 'ae'], RoleLevel::Admin);
    $off = Fx::storeId('ae');
    // Written straight in, before the first request reads the stores.
    DB::table('ops.daily_report_settings')->insert(['id' => strtolower((string) Str::ulid()), 'staff_id' => $staff, 'store_id' => $off, 'is_on' => true, 'send_at' => '07:30', 'next_due_at' => now()->addDay(), 'version' => 1, 'updated_at' => now()]);
    DB::table('platform.stores')->where('id', $off)->update(['is_active' => false]);
    cache()->flush();
    $browser = dailyReportsScreenStaff($staff);

    $browser->get('/admin/daily-reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('stores', 1)
        ->has('emailStores', 2)
        ->where('emailStores.1.id', $off)
        ->where('emailStores.1.isOn', false)
        ->where('zones.'.$off, fn (string $zone): bool => $zone !== ''));

    // And they can still stop it.
    $browser->post('/admin/daily-reports/emails', ['store' => $off, 'on' => false, 'sendAt' => '07:30'], dailyReportsScreenInertia())->assertRedirect();
    expect(DB::table('ops.daily_report_settings')->where('store_id', $off)->value('is_on'))->toBeFalse();
});

it('offers the menu entry only to whoever may use it', function () {
    $menu = static fn (string $staffId): array => array_values(array_intersect(array_column(array_merge(...array_column(dailyReportsScreenStaff($staffId)->get('/admin')->viewData('page')['props']['menu'], 'entries')), 'key'), ['daily_reports']));

    expect($menu(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin)))->toBe(['daily_reports'])
        ->and($menu(Fx::staffWith(['ops.report.view'], ['sa'])))->toBe([]);
});
