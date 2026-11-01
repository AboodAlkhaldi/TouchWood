<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Application\Gateway\KeyTester;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The Gateway page in a real browser (payments.md §4.2), at a phone's width and a desktop's: a key added through its
| dialog and shown by its last 6 characters only, on and off with a dialog saying what happens, the Test banner over
| other pages, the timers saved in the order that keeps each step allowed.
|
| Deliberately no RefreshDatabase, as the other browser tests: the gateway made here goes again after each test, so
| later files see no store in Test.
*/

afterEach(function () {
    DB::table('payments.gateway_keys')->delete();
    DB::table('payments.gateways')->delete();
    DB::table('platform.settings')->where('key', 'like', 'payments.timers.%')->delete();
});

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
});

function paymentsGatewayPanel(): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

function paymentsGatewayWide(mixed $page): string
{
    return (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');
}

it('adds a key, switches the gateway on, shows the Test banner elsewhere, and saves the timers', function () {
    $page = paymentsGatewayPanel();
    $page->resize(375, 812);
    $page->navigate('/admin/payments/gateway?store=sa', BROWSER_PAGE_LOAD)->assertPresent('[data-test="gateway-state"]');
    expect(paymentsGatewayWide($page))->toBe('', 'the Gateway page scrolls sideways');

    $page->click('[data-test="key-TEST-change"]');
    expect(browserUntil($page, "!!document.querySelector('#gateway-key')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('#gateway-key').type"))->toBe('password');
    $page->type('#gateway-key', 'SECRETKEYabc123XYZ789')->type('#gateway-secret', 'NOTICESECRETqwe456')->type('#gateway-expires', now()->addYear()->format('Y-m-d'))->click('[data-test="key-save"]');
    expect(browserUntil($page, "document.body.innerText.includes('Key tested and saved.')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"key-TEST-last6\"]').textContent"))->toBe('XYZ789')
        ->and((string) $page->script('document.documentElement.innerHTML.includes("SECRETKEYabc123XYZ789") ? "there" : "gone"'))->toBe('gone');

    $page->click('[data-test="gateway-switch"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"gateway-confirm\"]')"))->toBeTrue();
    $page->click('[data-test="gateway-confirm"]');
    expect(browserUntil($page, "document.body.innerText.includes('Online payment is on.')"))->toBeTrue();

    $page->navigate('/admin/account', BROWSER_PAGE_LOAD)->assertPresent('[data-test="admin-banner"]');
    expect(paymentsGatewayWide($page))->toBe('', 'the banner scrolls the page sideways');

    // Window up past the old hold, wait down, hold up: refused in any order but the one that keeps each step allowed.
    $page->resize(1280, 800);
    $page->navigate('/admin/payments/gateway?store=sa', BROWSER_PAGE_LOAD)->type('#timer-window', '14')->type('#timer-wait', '1')->type('#timer-hold', '20')->click('[data-test="timers-save"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"timers-saved\"]')"))->toBeTrue()
        ->and((int) json_decode((string) DB::table('platform.settings')->where('key', 'payments.timers.window_minutes')->value('value')))->toBe(14)
        ->and((int) json_decode((string) DB::table('platform.settings')->where('key', 'payments.timers.wait_minutes')->value('value')))->toBe(1)
        ->and((int) json_decode((string) DB::table('platform.settings')->where('key', 'payments.timers.hold_minutes')->value('value')))->toBe(20);
});
