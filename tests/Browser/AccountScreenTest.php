<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Pest\Browser\Api\PendingAwaitablePage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| "Account & settings" in a real browser (stage 2b, step 2, frontend.md §3.2).
|
| The feature tests beside this one already know the server sends the right data. What they cannot
| know is whether a person can use it: whether the screen draws at all, whether pressing a tab does
| anything, whether a refusal appears anywhere a person is looking. Step 1 shipped six faults that
| every other suite called green, which is why this file exists.
|
| No RefreshDatabase, as the sign-in screen's tests have none: it migrates fresh and then works
| inside a transaction, and in this suite the schemas were gone by the second test (tried, and it
| failed, 2026-09-23). So the browser suite runs against whatever `composer check` migrated, and
| seeds what it needs itself.
|
| Which means the cache has to be emptied first. The last run leaves the store directory warm while
| RefreshDatabase rolls its rows back, so a cached list of stores outlives the stores themselves -
| and Fx::storeId('sa') then answers with an empty string, which fails as `"" is not a store id`
| (found by running it, 2026-09-23).
*/

beforeEach(function () {
    // The browser tests serve the application inside this very process, so they inherit the
    // suite's session driver - and phpunit.xml forces "array", which keeps nothing between
    // requests. A refusal flashed on a redirect would be gone by the time the page rendered.
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A browser that has signed in completely, as a staff member who reads the panel in Arabic.
 *
 * The panel's language, with no cookie to say otherwise, is the person's own saved language
 * (amendment 16) - and the fixture's is English, which is why every Arabic assertion below would
 * otherwise be looking at an English page.
 *
 * Named after this file's subject: a function declared in a Pest file is global to the whole
 * suite, so two files sharing a name stop every run.
 */
function accountScreenSignedIn(): PendingAwaitablePage
{
    $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'ar']);

    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
    // One URL gives one page; visit() only hands back a collection when it is given several.
    $page = visit('/admin/sign-in');

    $page->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        // The submit button, not the heading above it - both say the same words, and pressing by
        // text finds the heading and does nothing at all (step 1, found by running it).
        ->click('button[type="submit"]');

    // The whole code goes into the first box, the one marked one-time-code: the screen spreads it
    // across the rest, exactly as it does when a browser fills it in from the message.
    // The click above only dispatches the submit; the code is not recorded until the server has
    // answered it. Waiting for the code screen first is what makes reading it reliable (this
    // raced, and lost, 2026-09-24).
    $page->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    return $page;
}

it('draws the account screen, rather than merely answering 200', function () {
    // The white-page bug in one assertion: the server can answer perfectly while the browser fails
    // to bring the page to life, and every other suite calls that green.
    accountScreenSignedIn()
        ->navigate('/admin/account')
        ->assertSee('الحساب والإعدادات')
        ->assertSee('الاسم الأول')
        ->assertSee('بريد العمل')
        // The three tabs are really there, not just the first one's fields.
        ->assertSee('الأمان')
        ->assertSee('التنبيهات')
        ->assertNoJavaScriptErrors();
});

it('turns the account screen around for Arabic, not only the words', function () {
    // Whether the layout mirrors is decided by <html dir>, and a screen can be perfectly
    // translated while still running the wrong way.
    $page = accountScreenSignedIn();

    // Asserted on the account screen itself first, so this cannot quietly pass while sitting on
    // the sign-in page, which is Arabic too.
    $page->navigate('/admin/account')->assertSee('الحساب والإعدادات');

    expect($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('document.documentElement.lang'))->toBe('ar');
});

it('shows another tab when that tab is pressed', function () {
    accountScreenSignedIn()
        ->navigate('/admin/account')
        ->assertSee('الدولة')
        ->click('[data-test="tab-security"]')
        ->assertSee('تغيير كلمة المرور')
        // The tab that was open is really gone, rather than merely scrolled past.
        ->assertDontSee('الدولة')
        ->assertNoJavaScriptErrors();
});

it('says why a password change was refused, where the person is looking', function () {
    // A refusal that appears only as a toast at the foot of a tall page, for six seconds, reads as
    // nothing having happened at all.
    accountScreenSignedIn()
        ->navigate('/admin/account?tab=security')
        ->type('#current_password', 'not the right password at all')
        ->type('#password', 'a considerably longer password')
        ->type('#password_repeat', 'a considerably longer password')
        ->click('[data-test="save-password"]')
        // Access's own wording, never one this screen invented (§3.2). It used to name the field
        // as `current_password`, the key the code uses; the key is now looked up in the module's
        // own words before it goes into the sentence (owner, 2026-09-24).
        ->assertSee('قيمة كلمة المرور الحالية غير صالحة.');
});

it('asks for the code once the password behind a phone change is right', function () {
    accountScreenSignedIn()
        ->navigate('/admin/account')
        ->click('[data-test="open-phone-dialog"]')
        // The dialog is really open: its own words are on the screen now and were not before.
        ->assertSee('الرقم الجديد')
        ->type('#new_phone', Fx::phone())
        ->type('#phone_current_password', Fx::STAFF_PASSWORD)
        ->click('[data-test="send-phone-code"]')
        // The second step of the same dialog, which appears only because the first one succeeded.
        ->assertSee('الرمز الذي أرسلناه')
        ->assertNoJavaScriptErrors();
});

it('refuses a phone change behind a wrong password, inside the dialog', function () {
    accountScreenSignedIn()
        ->navigate('/admin/account')
        ->click('[data-test="open-phone-dialog"]')
        ->type('#new_phone', Fx::phone())
        ->type('#phone_current_password', 'not the right password at all')
        ->click('[data-test="send-phone-code"]')
        // Said inside the dialog the person is looking at, not only behind it.
        ->assertSee('قيمة كلمة المرور الحالية غير صالحة.')
        // And it has not moved on to asking for a code that was never sent.
        ->assertDontSee('الرمز الذي أرسلناه');
});

it('saves a notification switch as it is flipped, and says so', function () {
    accountScreenSignedIn()
        ->navigate('/admin/account?tab=notifications')
        ->assertSee('قرب نفاد المخزون')
        ->click('[data-test="switch-email-LOW_STOCK"]')
        // The toast is the only thing that tells a person a switch with no Save button saved.
        ->assertSee('تم الحفظ.')
        ->assertNoJavaScriptErrors();
});

it('shows where I am signed in, and marks this browser', function () {
    accountScreenSignedIn()
        ->navigate('/admin/account?tab=sessions')
        ->assertSee('أين أنت مسجَّل الدخول')
        // The browser reading the page says so about itself.
        ->assertSee('هذا المتصفّح')
        ->assertNoJavaScriptErrors();
});

it('signs out everywhere, and the browser that pressed it is out too', function () {
    // The case the whole feature was asked for: an account lent to somebody, and wanted back.
    $page = accountScreenSignedIn()->navigate('/admin/account?tab=sessions');

    // Asked once before doing it: this signs the person pressing it out.
    $page->click('[data-test="sign-out-everywhere"]')
        ->click('[data-test="confirm-sign-out-everywhere"]')
        // No session left, so the panel asks them to sign in again.
        ->assertPathIs('/admin/sign-in')
        ->assertNoJavaScriptErrors();
});
