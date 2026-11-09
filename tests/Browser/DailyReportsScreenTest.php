<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\DailyReportFixtures as Dx;

use function Pest\Laravel\seed;

/*
| O9, Daily Reports in a real browser (ops.md §4.15): make today's and a past day's report, the row on top with its
| Download; the email's switch and its time, saved on leaving the box, and 00:00 refused as typed; a store switched
| off under an email; Arabic, and a phone.
|
| Deliberately no RefreshDatabase, as the other browser tests. Each test's staff are its own, and so are their reports
| and emails; a report's row is found by its id, because the list holds every run's.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    // A sync queue would run the not-yet-due email at once, and it puts itself back, for ever.
    Queue::fake();
    Storage::fake(LocalReportFiles::DISK);
    Dx::fakeRenderer();
});

function dailyReportsBrowser(string $staffId, string $locale = 'en'): mixed
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);

    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

    $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();
    $page->navigate('/admin/daily-reports', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"make-report\"]') !== null || document.querySelector('[data-test=\"no-store\"]') !== null"))->toBeTrue();

    return $page;
}

function dailyReportsFits(mixed $page): void
{
    expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
}

function dailyReportsRow(string $staffId): stdClass
{
    return DB::table('ops.daily_reports')->where('made_by', $staffId)->orderByDesc('made_at')->orderByDesc('id')->first()
        ?? throw new RuntimeException('No report was made.');
}

it('makes today\'s report, on top with its Download, and says Report ready', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $page = dailyReportsBrowser($staff);

    $page->click('[data-test="make-report"]');
    expect(browserSoon($page, "document.body.textContent.includes('Report ready.')"))->toBeTrue();
    $today = dailyReportsRow($staff);
    expect(browserSoon($page, "document.querySelector('[data-test=\"report-{$today->id}\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test^=\"report-\"]').dataset.test"))->toBe("report-{$today->id}")
        ->and((string) $page->script("document.querySelector('[data-test=\"report-{$today->id}\"] [data-test=\"download\"]').getAttribute('href')"))->toEndWith("/admin/daily-reports/{$today->id}/pdf")
        ->and($page->script("document.querySelector('[data-test=\"report-{$today->id}\"] [data-test=\"past-day\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    dailyReportsFits($page);
});

it('makes a past day\'s report, opening on yesterday, and marks it Past day', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $page = dailyReportsBrowser($staff);

    $page->click('[data-test="make-past"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"make-date\"]') !== null"))->toBeTrue();

    // Today can't be chosen: the calendar stops at yesterday.
    $page->click('[data-test="make-date"]');
    expect(browserSoon($page, "document.querySelector('[role=\"grid\"] [data-today=\"true\"] button')?.disabled === true"))->toBeTrue();
    $page->script("(() => { document.querySelector('[data-test=\"make-date\"]').click(); })()");

    $page->click('[data-test="make-report"]');
    expect(browserSoon($page, "document.body.textContent.includes('Report ready.')"))->toBeTrue();
    $row = dailyReportsRow($staff);
    expect((bool) $row->past_day)->toBeTrue()
        ->and(substr((string) $row->day, 0, 10))->toBe(CarbonImmutable::now('Asia/Riyadh')->subDay()->format('Y-m-d'))
        ->and(browserSoon($page, "document.querySelector('[data-test=\"report-{$row->id}\"] [data-test=\"past-day\"]')?.textContent === 'Past day'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    dailyReportsFits($page);
});

it('saves a time typed then left for the switch, both of them, with no false refusal', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $store = Fx::storeId('sa');
    $page = dailyReportsBrowser($staff);
    $row = "email-{$store}";
    $setting = fn (): ?stdClass => DB::table('ops.daily_report_settings')->where('staff_id', $staff)->where('store_id', $store)->first();

    // Pressing the switch leaves the box first: two saves a moment apart, and the last one stands.
    $page->type("[data-test=\"{$row}-time\"]", '07:30');
    $page->click("[data-test=\"{$row}-switch\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-next\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"{$row}-error\"]') === null", 2))->toBeTrue();
    usleep(500_000);
    expect($page->script("document.querySelector('[data-test=\"{$row}-error\"]') === null"))->toBeTrue()
        ->and((bool) $setting()?->is_on)->toBeTrue()
        ->and(substr((string) $setting()?->send_at, 0, 5))->toBe('07:30');
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    dailyReportsFits($page);
});

it('keeps a time saved while the email is off, off', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $store = Fx::storeId('sa');
    $page = dailyReportsBrowser($staff);
    $row = "email-{$store}";

    $page->type("[data-test=\"{$row}-time\"]", '08:15');
    $page->script("(() => { document.querySelector('[data-test=\"{$row}-time\"]').blur(); })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-saved\"]') !== null"))->toBeTrue();
    $saved = DB::table('ops.daily_report_settings')->where('staff_id', $staff)->first();
    expect((bool) $saved?->is_on)->toBeFalse()
        ->and(substr((string) $saved?->send_at, 0, 5))->toBe('08:15')
        ->and($page->script("document.querySelector('[data-test=\"{$row}-next\"]') === null"))->toBeTrue();

    $page->resize(375, 812);
    dailyReportsFits($page);
});

it('says once that there is no store to choose, when every store of theirs is off, and still shows the list', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['ae'], RoleLevel::Admin);
    $off = Fx::storeId('ae');
    DB::table('platform.stores')->where('id', $off)->update(['is_active' => false]);
    cache()->flush();

    try {
        $page = dailyReportsBrowser($staff);

        expect(browserSoon($page, "document.querySelector('[data-test=\"no-store\"]') !== null"))->toBeTrue()
            ->and((int) $page->script("document.body.innerText.split('A Super Admin can switch your store on.').length - 1"))->toBe(1)
            ->and($page->script("document.querySelector('[data-test=\"make-report\"]') === null"))->toBeTrue()
            ->and($page->script("document.getElementById('reports-made') !== null"))->toBeTrue();
        $page->assertNoJavaScriptErrors();

        $page->resize(375, 812);
        dailyReportsFits($page);
    } finally {
        DB::table('platform.stores')->where('id', $off)->update(['is_active' => true]);
        cache()->flush();
    }
});

it('turns the email on, shows when it goes next, saves a new time on leaving the box, and turns it off', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $store = Fx::storeId('sa');
    $page = dailyReportsBrowser($staff);
    $row = "email-{$store}";
    $setting = fn (): ?stdClass => DB::table('ops.daily_report_settings')->where('staff_id', $staff)->where('store_id', $store)->first();

    // Never set: off at 21:00, and nothing saved until it is touched.
    expect($page->script("document.querySelector('[data-test=\"{$row}-time\"]').value"))->toBe('21:00')
        ->and($setting())->toBeNull();

    $page->click("[data-test=\"{$row}-switch\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-next\"]')?.textContent.startsWith('Next:')"))->toBeTrue()
        ->and((bool) $setting()?->is_on)->toBeTrue();

    $page->type("[data-test=\"{$row}-time\"]", '07:30');
    $page->script("(() => { document.querySelector('[data-test=\"{$row}-time\"]').blur(); })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-saved\"]') !== null"))->toBeTrue()
        ->and(substr((string) $setting()?->send_at, 0, 5))->toBe('07:30');

    $page->click("[data-test=\"{$row}-switch\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-next\"]') === null"))->toBeTrue()
        ->and((bool) $setting()?->is_on)->toBeFalse();
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    dailyReportsFits($page);
});

it('refuses 00:00 as it is typed, and keeps the switch off for a time that isn\'t complete', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $store = Fx::storeId('sa');
    $page = dailyReportsBrowser($staff);
    $row = "email-{$store}";

    $page->type("[data-test=\"{$row}-time\"]", '00:00');
    expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-error\"]')?.textContent.includes('00:00')"))->toBeTrue();

    $page->type("[data-test=\"{$row}-time\"]", '7');
    $page->click("[data-test=\"{$row}-switch\"]");
    usleep(300_000);
    expect($page->script("document.querySelector('[data-test=\"{$row}-switch\"]').getAttribute('data-state')"))->toBe('unchecked')
        ->and(DB::table('ops.daily_report_settings')->where('staff_id', $staff)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    dailyReportsFits($page);
});

it('keeps a store switched off under an email on the page, marked Off, and lets it be stopped', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa', 'ae'], RoleLevel::Admin);
    $off = Fx::storeId('ae');
    DB::table('ops.daily_report_settings')->insert(['id' => strtolower((string) Str::ulid()), 'staff_id' => $staff, 'store_id' => $off, 'is_on' => true, 'send_at' => '07:30', 'next_due_at' => now()->addDay(), 'version' => 1, 'updated_at' => now()]);
    DB::table('platform.stores')->where('id', $off)->update(['is_active' => false]);
    cache()->flush();

    try {
        $page = dailyReportsBrowser($staff);
        $row = "email-{$off}";

        expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}\"] [data-test=\"store-off\"]') !== null"))->toBeTrue();
        $page->click("[data-test=\"{$row}-switch\"]");
        expect(browserSoon($page, "document.querySelector('[data-test=\"{$row}-next\"]') === null"))->toBeTrue()
            ->and((bool) DB::table('ops.daily_report_settings')->where('staff_id', $staff)->value('is_on'))->toBeFalse();

        $page->resize(375, 812);
        dailyReportsFits($page);
    } finally {
        DB::table('platform.stores')->where('id', $off)->update(['is_active' => true]);
        cache()->flush();
    }
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa'], RoleLevel::Admin);
    $page = dailyReportsBrowser($staff, 'ar');

    $page->click('[data-test="make-report"]');
    expect(browserSoon($page, "document.querySelector('[data-test^=\"report-\"]') !== null"))->toBeTrue();

    // The moment it goes next, in Arabic words and 0-9.
    $row = 'email-'.Fx::storeId('sa');
    $page->click("[data-test=\"{$row}-switch\"]");
    expect(browserSoon($page, "/[0-9]/.test(document.querySelector('[data-test=\"{$row}-next\"]')?.textContent ?? '')"))->toBeTrue();

    $page->resize(375, 812);

    expect(browserSoon($page, "document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and((bool) $page->script('/[٠-٩]/.test(document.body.textContent)'))->toBeFalse();
    dailyReportsFits($page);
    $page->assertNoJavaScriptErrors();
});
