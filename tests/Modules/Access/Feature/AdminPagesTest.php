<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Ssr\SsrRenderFailed;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Platform\Public\PlatformPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

const PAGE_PASSWORD = 'a long enough password';

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * One shared prop of a rendered page, as a string.
 *
 * Read through assertInertia rather than AssertableInertia::fromTestResponse(), whose signature
 * names a narrower response type than a browser of ours hands back.
 *
 * @param  TestResponse<Response>  $response
 */
function pageProp(TestResponse $response, string $name): string
{
    $found = '';

    $response->assertInertia(function (AssertableInertia $inertia) use ($name, &$found): void {
        /** @var array<string, mixed> $props */
        $props = $inertia->toArray()['props'];
        $value = $props[$name] ?? null;
        $found = is_string($value) ? $value : '';
    });

    return $found;
}

/**
 * Signs in far enough to reach the code step: the right password, from an untrusted browser.
 */
function reachCodeStep(AdminBrowser $browser, string $staffId): void
{
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => PAGE_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
}

/**
 * Stage 2b, step 1. The foundation - Inertia, the layouts, the words, the theme - proved by the
 * screens that actually use it rather than described.
 */
describe('the admin sign-in screens', function () {
    it('renders the sign-in page as an Inertia page, in Arabic, right to left', function () {
        $page = (new AdminBrowser)->get('/admin/sign-in');

        $page->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Access/Admin/SignIn')
                ->where('locale', 'ar')
                ->where('direction', 'rtl')
                // Arabic until this browser says otherwise, and light until chosen. The campaign
                // is the base one - a campaign like any other, with its own light and dark.
                ->where('theme.mode', 'light')
                ->where('theme.campaign', 'base')
            );

        // The words travel with the page: there are no separate frontend translation files. Read
        // out of the map rather than through a dotted path, because the keys hold dots themselves.
        $page->assertInertia(function (AssertableInertia $inertia) {
            /** @var array<string, string> $words */
            $words = $inertia->toArray()['props']['translations'];

            expect($words['access::auth.sign_in'] ?? null)->toBe('تسجيل الدخول')
                // The shell's words travel too: the sign-in layout carries the theme toggle, and a
                // page that ships its own words but not its layout's shows the layout's keys raw.
                ->and($words)->toHaveKey('admin.theme.dark')
                // Still only the files this screen named, never the system's whole dictionary.
                ->and($words)->not->toHaveKey('access::permissions.access.staff.invite');
        });

        // The shell is rendered by the server already holding the language and direction, so the
        // first paint is right and nothing flips (frontend.md §1.3).
        $page->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-mode="light"', false)
            ->assertSee('data-campaign="base"', false);
    });

    it('sends only the admin routes to an admin page, never the storefront\'s', function () {
        $page = (new AdminBrowser)->get('/admin/sign-in');

        $page->assertInertia(function (AssertableInertia $inertia) {
            /** @var array<string, mixed> $routes */
            $routes = $inertia->toArray()['props']['routes']['routes'];

            expect($routes)->toHaveKey('access.staff.sign-in')
                ->and($routes)->not->toHaveKey('storefront.account.register');
        });
    });

    it('names the number the code went to, masked, and never the number itself', function () {
        $staffId = Fx::staff();
        $phone = (string) DB::table('access.staff_users')->where('id', $staffId)->value('phone');
        $browser = new AdminBrowser('10.2.0.1');
        reachCodeStep($browser, $staffId);

        $browser->get('/admin/sign-in/code')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Access/Admin/SignInCode')
                ->where('maskedPhone', fn (?string $masked): bool => is_string($masked)
                    && str_ends_with($masked, mb_substr($phone, -3))
                    && ! str_contains($masked, mb_substr($phone, 1, 6)))
                // How many boxes is a setting, not a number written into the screen.
                ->where('length', 6)
            )
            // The whole number is nowhere in the page, not even in the shell.
            ->assertDontSee($phone, false);
    });

    it('sends someone to the sign-in page when they open the code screen out of turn', function () {
        // A3 opens only in its place in the flow (§3.1): with no pending sign-in behind it, the
        // screen can do nothing but confuse.
        (new AdminBrowser)->get('/admin/sign-in/code')->assertRedirect('/admin/sign-in');
        (new AdminBrowser)->get('/admin/sign-in/phone')->assertRedirect('/admin/sign-in');
    });

    it('sends someone already signed in away from the sign-in page', function () {
        $browser = signedInBrowser();

        $browser->get('/admin/sign-in')->assertRedirect('/admin');
        $browser->get('/admin/password/forgot')->assertRedirect('/admin');
    });

    it('shows nothing for an invitation or an email change whose link is no good', function () {
        // Opening a link never acts, and a link that is spent or expired says only that it is not
        // usable - never which of the two it was.
        $token = strtolower((string) Str::ulid());

        (new AdminBrowser)->get("/admin/invitation/{$token}")->assertRedirect('/admin/sign-in');
        (new AdminBrowser)->get("/admin/invitation/{$token}/code")->assertRedirect('/admin/sign-in');
        (new AdminBrowser)->get("/admin/email-change/{$token}")->assertRedirect('/admin/sign-in');
    });

    it('shows the password rule from the setting, not from the screen', function () {
        $token = strtolower((string) Str::ulid());

        (new AdminBrowser)->get("/admin/password/reset/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Access/Admin/ResetPassword')
                ->where('minimumLength', 12)
                ->where('token', $token)
            );
    });
});

describe('the admin panel itself', function () {
    it('opens on the home page with the menu of what this person may do', function () {
        // Nothing is registered here any more: every entry this test needs is a real one now.
        // They hold staff.view alone, so the staff entry is offered and Platform's - stores,
        // currencies, settings, media, audit - are not, which takes their groups with them.
        $browser = signedInBrowser([AccessPermissions::STAFF_VIEW]);

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $inertia) {
                $inertia->component('Admin/Home');

                /** @var list<array{key: string, entries: list<array{key: string}>}> $menu */
                $menu = $inertia->toArray()['props']['menu'];
                $groups = array_column($menu, 'key');

                // They hold staff.view and nothing else, so the audit entry is not offered at all -
                // and its whole group disappears with it (frontend.md §2.2).
                expect($groups)->toBe(['staff_and_permissions'])
                    ->and(array_column($menu[0]['entries'], 'key'))->toBe(['staff']);
            });
    });

    it('tells the panel who is looking at it, and which store they are in', function () {
        $browser = signedInBrowser([PlatformPermissions::STORE_VIEW]);

        $browser->get('/admin')->assertInertia(fn (AssertableInertia $inertia) => $inertia
            // The fixture's staff member, whose saved language is English.
            ->where('viewer.name', 'Staff Member')
            ->where('viewer.isSuperAdmin', false)
            ->where('viewer.roleLabel', fn (?string $role): bool => is_string($role) && $role !== '')
            // One store: the header shows its name, in the language being read, and there is
            // nothing to pick.
            ->where('store.fellBack', false)
            ->where('store.current.name', 'Saudi Arabia')
            ->where('store.available', fn (Collection $stores): bool => $stores->count() === 1)
        );
    });

    it('remembers the theme and the displayed language per browser, deciding both on the server', function () {
        $browser = new AdminBrowser('10.2.0.5');
        $browser->get('/admin/sign-in');

        $browser->post('/admin/preferences', ['preference' => 'theme', 'value' => 'dark']);
        $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'en']);

        $browser->get('/admin/sign-in')
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->where('theme.mode', 'dark')
                ->where('locale', 'en')
                ->where('direction', 'ltr')
            )
            // Written into the shell, so the very first paint is dark and in English.
            ->assertSee('data-mode="dark"', false)
            ->assertSee('lang="en"', false)
            ->assertInertia(function (AssertableInertia $inertia) {
                /** @var array<string, string> $words */
                $words = $inertia->toArray()['props']['translations'];

                expect($words['access::auth.sign_in'] ?? null)->toBe('Sign in');
            });
    });

    it('ignores a preference it does not know rather than failing', function () {
        $browser = new AdminBrowser('10.2.0.6');
        $browser->get('/admin/sign-in');

        $browser->post('/admin/preferences', ['preference' => 'theme', 'value' => 'neon'])->assertRedirect();

        $browser->get('/admin/sign-in')->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('theme.mode', 'light'));
    });

    it('still serves the page when the server renderer is down, and writes it down', function () {
        // Decided 2026-09-19: nobody sees an error because of SSR. With the renderer unreachable,
        // the page comes back whole and the browser renders it; the failure is logged so that a
        // renderer that has been down for a week is noticed.
        config(['inertia.ssr.enabled' => true, 'inertia.ssr.throw_on_error' => false]);
        Event::fake([SsrRenderFailed::class]);
        Http::fake(fn () => throw new ConnectionException('The renderer is not running.'));

        (new AdminBrowser)->get('/admin/sign-in')
            ->assertOk()
            // Rendered by the browser instead, so the shell is there with an empty Inertia root.
            ->assertSee('data-page', false);

        Event::assertDispatched(SsrRenderFailed::class);
    });

    it('carries the request token in the page, and a fresh one after signing in', function () {
        // The admin panel and the storefront each write a cookie called XSRF-TOKEN, on different
        // paths, so which one a browser hands back is its own business. The page carries the token
        // instead, and Laravel reads the header before it reaches for any cookie (owner,
        // 2026-09-22).
        $browser = new AdminBrowser('10.2.0.7');

        $before = pageProp($browser->get('/admin/sign-in'), 'csrfToken');

        expect($before)->not->toBe('');

        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
        reachCodeStep($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])
            ->assertRedirect('/admin');

        $after = pageProp($browser->get('/admin'), 'csrfToken');

        // Signing in regenerates the session, and the token with it. A page still carrying the old
        // one would be refused on its very next request.
        expect($after)->not->toBe('')
            ->and($after)->not->toBe($before);
    });

    it('signs a person out from the sidebar, and says so', function () {
        $browser = signedInBrowser();

        $browser->post('/admin/sign-out')->assertRedirect();
        $browser->get('/admin')->assertRedirect('/admin/sign-in');
    });
});

/**
 * A browser that has signed in completely: password, code, and the session that follows.
 *
 * @param  list<string>  $permissions
 */
function signedInBrowser(array $permissions = [AccessPermissions::STAFF_VIEW]): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, ['sa']);
    $browser = new AdminBrowser('10.2.0.'.random_int(20, 250));
    reachCodeStep($browser, $staffId);

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

it('sets a preference for the whole site, and clears the panel-scoped twin', function () {
    // The panel sets session.path to /admin for the length of its request, and Laravel's cookie
    // helper takes the current session's path - so these were written at /admin while the shop
    // wrote the same names at /. A browser that had seen both held two cookies of each name and
    // sent both, and which one the server read was its choice: the toggle changed a copy nobody
    // was reading and looked stuck (owner, 2026-09-26).
    $browser = signedInBrowser();

    $response = $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'ar']);

    $cookies = collect($response->headers->getCookies())
        ->filter(fn ($cookie): bool => $cookie->getName() === HandleInertiaRequests::LOCALE_COOKIE);

    // One written for the whole site, and one clearing the /admin copy any browser already holds
    // - which would otherwise stay until it expired, and might keep winning.
    $paths = $cookies->map(fn ($cookie): string => (string) $cookie->getPath())->sort()->values()->all();
    expect($paths)->toBe(['/', '/admin']);

    $written = $cookies->first(fn ($cookie): bool => $cookie->getPath() === '/');
    $forgotten = $cookies->first(fn ($cookie): bool => $cookie->getPath() === '/admin');

    // Both values are encrypted on the way out - even the empty one - so the expiry is what says
    // which is which: one is being kept, the other is being taken away.
    expect($written?->getExpiresTime())->toBeGreaterThan(time())
        ->and($forgotten?->getExpiresTime())->toBeLessThan(time());
});
