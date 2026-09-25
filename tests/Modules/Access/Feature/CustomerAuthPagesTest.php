<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The shop's sign-in screens (stage 2b step 4, frontend.md §3.6, F3-F6).
|
| The posts behind them have their own tests in CustomerSignInTest; these are about the pages: that
| each one draws, that it carries the numbers the settings actually hold rather than numbers
| written into a page, and that the guards send a person where the page makes sense.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Sessions live in the database, as in production: a page that asks who is signed in reads the
    // session on its own request (phpunit.xml's in-memory driver keeps nothing between them).
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A customer, and a browser already signed in as them.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite, so two
 * files sharing a name stop every run (the account screens' own note, 2026-09-23).
 *
 * @return array{0: string, 1: AdminBrowser}
 */
function authPageCustomer(): array
{
    $customerId = Fx::customer();
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])
        ->assertRedirect('/sa/en');

    return [$customerId, $browser];
}

describe('the pages a visitor sees (F3, F5, F6)', function () {
    it('draws the register page, with the password rule the setting holds', function () {
        (new AdminBrowser)->get('/sa/en/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Storefront/Register')
                ->where('minimumLength', 8)
                // The shop's frame comes with it: the store, and nobody signed in.
                ->where('shop.code', 'sa')
                ->where('shopper', null)
            );
    });

    it('draws the sign-in page, saying how long "keep me signed in" lasts', function () {
        (new AdminBrowser)->get('/sa/en/sign-in')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Storefront/SignIn')
                // Customers have this and staff do not (access.md §1.8).
                ->where('rememberDays', 30)
            );
    });

    it('draws both halves of the new-password flow', function () {
        (new AdminBrowser)->get('/sa/en/password/forgot')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Access/Storefront/ForgotPassword'));

        // The token is not checked by the page: a link that has expired is refused when the form is
        // sent, in the same words for every reason, so the page cannot be used to learn which
        // tokens exist.
        (new AdminBrowser)->get('/sa/en/password/reset/not-a-real-token')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Storefront/ResetPassword')
                ->where('token', 'not-a-real-token')
                ->where('minimumLength', 8)
            );
    });

    it('reads the page in the language of the address', function () {
        $page = (new AdminBrowser)->get('/sa/ar/sign-in')->assertOk();

        $page->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('locale', 'ar')
            ->where('direction', 'rtl')
        );

        // Read out of the map rather than through a dotted path, because the keys hold dots
        // themselves.
        $page->assertInertia(function (AssertableInertia $inertia) {
            /** @var array<string, string> $words */
            $words = $inertia->toArray()['props']['translations'];

            expect($words['access::auth.sign_in'] ?? null)->toBe('تسجيل الدخول')
                // The frame's words travel too: the header carries a theme toggle and a country
                // switch, and a page that ships its own words but not its layout's shows the
                // layout's keys raw, on the screen.
                ->and($words)->toHaveKey('admin.theme.dark')
                ->and($words)->toHaveKey('platform::stores.choose_title')
                // Still only the files this screen named, never the system's whole dictionary.
                ->and($words)->not->toHaveKey('access::permissions.access.staff.invite');
        });
    });
});

describe('the country page, which belongs to no store', function () {
    it('sends a customer who is signed in to their store, rather than failing', function () {
        // brand.com belongs to no store, and a customer's session is bounded by numbers that are
        // a store's own - how long "keep me signed in" lasts, how long they may be idle. Asking
        // for those on a page with no store threw, and every visitor who had ever signed in got a
        // 500 on the front door (found by the owner, 2026-09-25).
        [, $browser] = authPageCustomer();

        // The store lives in Laravel's Context, which is process-global: inside one test the store
        // resolved by the sign-in request is still set when the next one runs, and it hid this bug
        // completely. A real request starts with none, so the test must too.
        Context::flush();

        $browser->get('/')->assertRedirect('/sa/en');
    });

    it('still lets a visitor who has chosen nothing choose', function () {
        (new AdminBrowser)->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Platform/Storefront/ChooseStore'));
    });
});

describe('where a page sends somebody it is not for', function () {
    it('sends a customer who is already signed in back to the store', function (string $path) {
        [, $browser] = authPageCustomer();

        $browser->get($path)->assertRedirect('/sa/en');
    })->with(['/sa/en/register', '/sa/en/sign-in', '/sa/en/password/forgot']);

    it('lets a customer who is signed in open their own reset link', function () {
        // The post signs them out and the reset goes on (access.md amendment 31), so the page it
        // comes from must not refuse them first.
        [, $browser] = authPageCustomer();

        $browser->get('/sa/en/password/reset/some-token')->assertOk();
    });
});

describe('confirming an email address (F4)', function () {
    it('shows a signed-in customer where the link went, and for how long', function () {
        [, $browser] = authPageCustomer();

        $browser->get('/sa/en/verify-email')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Storefront/VerifyEmail')
                ->where('email', 'sara@example.test')
                ->where('linkHours', 24)
                // The header says the same thing, which is how anyone reaches this page at all.
                ->where('shopper.emailVerified', false)
            );
    });

    it('has nothing to say to a visitor, and sends them to sign in', function () {
        (new AdminBrowser)->get('/sa/en/verify-email')->assertRedirect('/sa/en/sign-in');
    });

    it('sends a customer whose address is already confirmed back to the store', function () {
        [$customerId, $browser] = authPageCustomer();
        DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => now()]);

        $browser->get('/sa/en/verify-email')->assertRedirect('/sa/en');

        // And the header stops asking, which is the only way anybody reaches that page. Asserted
        // both ways round on purpose: "not confirmed" was true for everyone while the middleware
        // asked the reader for a column it does not answer with (2026-09-24).
        $browser->get('/sa/en')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shopper.emailVerified', true));
    });
});
