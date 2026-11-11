<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Modules\Content\Application\Command\SaveTheme\SaveTheme;
use Modules\Content\Application\Command\SaveTheme\SaveThemeHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Presentation\Http\ThemePreviewCookie;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Preview Theme over HTTP (content.md W5b): `POST /admin/content/themes/{theme}/preview` gives the presser's browser the
| signed cookie and opens the theme's first store's home; a staff member who is not a Super Admin is refused and gets
| none. How the shop draws it is PR 9's.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Sleep::fake();
});

function themePreviewBrowser(string $staff): AdminBrowser
{
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staff)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('gives a Super Admin\'s browser the signed cookie and goes back, the staff view then opening the theme\'s first store, and refuses anyone else', function () {
    $super = Fx::staff(superAdmin: true);
    $theme = Fx::asSystem(fn (): string => app(SaveThemeHandler::class)->handle(new SaveTheme(null, 0, 'رمضان', 'Ramadan', '2027-02-18 00:00', '2027-03-19 00:00', [Fx::storeId('ae'), Fx::storeId('sa')])));
    $first = min(Fx::storeId('ae'), Fx::storeId('sa')) === Fx::storeId('sa') ? 'sa' : 'ae';
    $page = "/admin/content/themes/{$theme}";

    $browser = themePreviewBrowser($super);
    // Back to the theme's page: the shop shows a preview only in a staff view (content.md 9.3d D5), which W5b opens
    // next through Access's own route - a redirect straight to the shop opened none (PR 9's review).
    $browser->post("{$page}/preview", [], ['HTTP_REFERER' => url($page)])->assertRedirect($page);
    $value = (string) $browser->cookie(ThemePreviewCookie::NAME);

    expect(app(ThemePreviewCookie::class)->themeFor(Request::create("/{$first}/ar", cookies: [ThemePreviewCookie::NAME => $value]), $super))->toBe($theme);

    $opened = $browser->post('/admin/staff-view', ['store' => $first], ['HTTP_REFERER' => url($page)]);
    expect((string) ($opened->headers->get('X-Inertia-Location') ?? $opened->headers->get('Location')))->toContain("/{$first}/");

    $other = Fx::staffWith([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::BANNER_MANAGE], ['sa', 'ae']);
    $refused = themePreviewBrowser($other);
    $refused->post("/admin/content/themes/{$theme}/preview")->assertForbidden();

    expect($refused->cookie(ThemePreviewCookie::NAME))->toBeNull();
});

it('sets the cookie Secure as the session\'s is, HTTP only, and takes it away on Stop Preview', function (bool $secure) {
    config(['session.secure' => $secure]);
    $cookie = app(ThemePreviewCookie::class);
    $issued = $cookie->issue('01jz0000000000000000000000', Fx::staff(superAdmin: true));
    $forgotten = $cookie->forget();

    expect($issued->isSecure())->toBe($secure)
        ->and($issued->isHttpOnly())->toBeTrue()
        ->and($forgotten->isSecure())->toBe($secure)
        ->and($forgotten->getValue())->toBe('')
        ->and($forgotten->isCleared())->toBeTrue();
})->with(['secure' => [true], 'not secure' => [false]]);
