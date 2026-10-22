<?php

declare(strict_types=1);

use App\Http\AdminArea;
use App\Http\FormErrors;
use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Presentation\Http\Controller\PanelNotFoundController;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| The admin panel's error page (frontend.md §2.6; app/Http/ProblemDetails): a page somebody signed in to the
| panel went to and could not have is answered in the panel's frame, in the page's language, with the
| error's translated title and its own sentence - or its status's, when it wrote none - and never the
| message written for developers. What stays on Laravel's page is proved too: a form sent, an address no
| route answers, and anybody not signed in.
|
| Sync's own not-found read is proved where its screens are (tests/Modules/Sync/Feature/SyncScreensTest).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'app.debug' => false]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    Route::prefix(AdminArea::PREFIX)
        ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
        ->group(function (): void {
            Route::get('_test/missing', fn () => abort(404, 'Developer note: no such row in table x.'));
            Route::get('_test/not-yours', fn () => throw new Unauthorized('platform.store.update'));
            Route::get('_test/conflict', fn () => throw panelErrorUntranslated(ErrorCategory::Conflict));
            Route::get('_test/invalid', fn () => throw panelErrorUntranslated(ErrorCategory::Invalid));
            Route::get('_test/unsupported', fn () => throw panelErrorUntranslated(ErrorCategory::Unsupported));
            Route::get('_test/too-large', fn () => throw panelErrorUntranslated(ErrorCategory::TooLarge));
            // A refusal naming the rule it broke (FormErrors::ruleKey): Pricing's bands, each cheaper than the one before.
            Route::get('_test/rule', fn () => throw new BandsInvalid(BandsInvalid::ORDER));
            Route::post('_test/missing', fn () => abort(404, 'Developer note: no such row in table x.'));
        });

    Route::post('/_test/action-not-yours', fn () => throw new Unauthorized('platform.store.update'));

    // The sign-in pages are the panel's too, but nobody is signed in on them.
    Route::prefix(AdminArea::PREFIX)
        ->middleware(AdminArea::MIDDLEWARE)
        ->get('_test/signed-out-missing', fn () => abort(404, 'Developer note: no such row in table x.'));
});

/** A business error of no module's, with no words written for it: its message is a developer's. */
function panelErrorUntranslated(ErrorCategory $category): DomainError
{
    return new class($category) extends DomainError
    {
        public function __construct(private readonly ErrorCategory $kind)
        {
            parent::__construct('Developer note: row 17 locked by job 4.');
        }

        public function type(): string
        {
            return 'testing.nothing_written';
        }

        public function category(): ErrorCategory
        {
            return $this->kind;
        }

        public function context(): array
        {
            return [];
        }
    };
}

/** Signed in through the panel's door, the panel in this language. */
function panelErrorSignedIn(string $locale = 'en'): AdminBrowser
{
    $staff = Fx::staff();
    DB::table('access.staff_users')->where('id', $staff)->update(['locale' => $locale]);
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staff)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('answers a page that is not there in the panel\'s frame, with the status\'s sentence and the way back, in the page\'s language', function (string $locale, string $title, string $sentence, string $back) {
    $response = panelErrorSignedIn($locale)->get('/admin/_test/missing');

    $response->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Error')
        ->where('status', 404)
        ->where('title', $title)
        ->where('detail', $sentence)
        ->where('locale', $locale)
        ->where('direction', $locale === 'ar' ? 'rtl' : 'ltr')
        ->where('translations', fn (Collection $words): bool => $words->get('admin.error.back') === $back)
        ->where('viewer', fn ($viewer): bool => $viewer !== null));

    expect($response->getContent())->not->toContain('Developer note');
})->with([
    'English' => ['en', 'Page Not Found', 'There\'s nothing at this address: it was removed, or the link is wrong.', 'Back to Home'],
    'Arabic' => ['ar', 'الصفحة غير موجودة', 'لا يوجد شيء على هذا العنوان: أُزيل، أو الرابط غير صحيح.', 'العودة إلى الرئيسية'],
]);

it('says a page refused on permission as a page, not an action, in the page\'s language (owner, 2026-10-20)', function (string $locale, string $title, string $sentence) {
    panelErrorSignedIn($locale)->get('/admin/_test/not-yours')->assertForbidden()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Error')
        ->where('status', 403)
        ->where('title', $title)
        ->where('detail', $sentence));
})->with([
    'English' => ['en', 'Not Allowed', 'You don\'t have access to this page.'],
    'Arabic' => ['ar', 'غير مسموح', 'ليست لديك صلاحية لفتح هذه الصفحة.'],
]);

it('keeps an action\'s own words for a refusal on permission', function (string $locale, string $sentence) {
    app()->setLocale($locale);

    // An action refused: its screen says it (FormErrors), and a client asking for JSON gets the same words.
    postJson('/_test/action-not-yours')->assertForbidden()->assertJsonPath('detail', $sentence);
    expect(FormErrors::message(new Unauthorized('platform.store.update')))->toBe($sentence);
})->with([
    'English' => ['en', 'Couldn\'t do that: it isn\'t one of your jobs. Ask an administrator for it.'],
    'Arabic' => ['ar', 'تعذّر ذلك: ليس من صلاحياتك. اطلبه من المسؤول.'],
]);

it('says the status\'s sentence for an error that wrote none, in the page\'s language, never its developers\' message', function (string $locale, string $path, int $status, string $title, string $sentence) {
    $response = panelErrorSignedIn($locale)->get($path);

    $response->assertStatus($status)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Error')
        ->where('status', $status)
        ->where('title', $title)
        ->where('detail', $sentence));

    expect($response->getContent())->not->toContain('Developer note')
        ->and($response->getContent())->not->toContain('testing.nothing_written');
})->with([
    'a conflict' => ['en', '/admin/_test/conflict', 409, 'Conflict', 'Couldn\'t open this page: something changed meanwhile. Reload it and try again.'],
    'an invalid request' => ['en', '/admin/_test/invalid', 422, 'Invalid Request', 'Couldn\'t open this page: something in its address isn\'t valid.'],
    'an unsupported kind' => ['en', '/admin/_test/unsupported', 415, 'Unsupported', 'Couldn\'t open this page: what was sent isn\'t a kind it takes.'],
    'too large' => ['en', '/admin/_test/too-large', 413, 'Too Large', 'Couldn\'t open this page: what was sent is too large.'],
    'a conflict, in Arabic' => ['ar', '/admin/_test/conflict', 409, 'تعارض', 'تعذّر فتح هذه الصفحة: تغيّر شيء في الأثناء. أعد تحميلها وحاول مجددًا.'],
    'an invalid request, in Arabic' => ['ar', '/admin/_test/invalid', 422, 'طلب غير صالح', 'تعذّر فتح هذه الصفحة: في عنوانها ما ليس صالحًا.'],
    'an unsupported kind, in Arabic' => ['ar', '/admin/_test/unsupported', 415, 'غير مدعوم', 'تعذّر فتح هذه الصفحة: ما أُرسل من نوع لا تقبله.'],
    'too large, in Arabic' => ['ar', '/admin/_test/too-large', 413, 'الحجم كبير جدًا', 'تعذّر فتح هذه الصفحة: ما أُرسل كبير جدًا.'],
]);

it('says the words of the rule a refusal names, as a form says them', function (string $locale, string $title, string $sentence) {
    panelErrorSignedIn($locale)->get('/admin/_test/rule')->assertStatus(422)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Error')
        ->where('title', $title)
        ->where('detail', $sentence));
})->with([
    'English' => ['en', 'Check the quantity prices', 'Each band must cost less than the band before.'],
    'Arabic' => ['ar', 'تحقّق من أسعار الكميات', 'يجب أن يكون سعر كل شريحة أقل من سعر الشريحة التي قبلها.'],
]);

it('answers a visit made inside the panel, and a partial reload of another page, with the error page itself', function () {
    $browser = panelErrorSignedIn();
    $inertia = ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => (string) app(HandleInertiaRequests::class)->version(request())];

    foreach ([
        $browser->get('/admin/_test/missing', $inertia),
        // A page reloading part of itself - Show More, a drawer - whose address has gone: the whole error page, not the part.
        $browser->get('/admin/_test/missing', [...$inertia, 'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'Sync/Admin/Read', 'HTTP_X_INERTIA_PARTIAL_DATA' => 'lines']),
    ] as $response) {
        $response->assertNotFound()->assertHeader('X-Inertia', 'true');

        expect($response->json('component'))->toBe('Admin/Error')
            ->and($response->json('props.status'))->toBe(404)
            ->and($response->json('props.detail'))->toBe('There\'s nothing at this address: it was removed, or the link is wrong.')
            ->and($response->json('props.menu'))->not->toBeNull()
            ->and($response->getContent())->not->toContain('Developer note');
    }
});

it('answers a HEAD request with the status alone', function () {
    $response = panelErrorSignedIn()->head('/admin/_test/missing');

    $response->assertNotFound();

    expect($response->getContent())->not->toContain('Developer note');
});

it('says no developer message with debug on either', function () {
    config(['app.debug' => true]);
    $browser = panelErrorSignedIn();

    foreach (['/admin/_test/missing', '/admin/_test/conflict'] as $path) {
        $response = $browser->get($path);

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Error'));

        expect($response->getContent())->not->toContain('Developer note')
            ->and($response->getContent())->not->toContain('testing.nothing_written');
    }
});

it('answers a mistyped panel address with the panel\'s not-found page, in the page\'s language (owner, 2026-10-20)', function (string $locale, string $title, string $sentence) {
    foreach (['/admin/staf', '/admin/sync/nothing/here', '/admin/sign-inx'] as $address) {
        panelErrorSignedIn($locale)->get($address)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Error')
            ->where('status', 404)
            ->where('title', $title)
            ->where('detail', $sentence)
            ->where('viewer', fn ($viewer): bool => $viewer !== null));
    }
})->with([
    'English' => ['en', 'Page Not Found', 'There\'s nothing at this address: it was removed, or the link is wrong.'],
    'Arabic' => ['ar', 'الصفحة غير موجودة', 'لا يوجد شيء على هذا العنوان: أُزيل، أو الرابط غير صحيح.'],
]);

it('sends somebody signed out from a mistyped panel address to sign in first, and leaves the sign-in pages\' addresses alone', function () {
    (new AdminBrowser)->get('/admin/staf')->assertRedirect('/admin/sign-in');
    (new AdminBrowser)->get('/admin/sign-in')->assertOk();

    // An address under the sign-in pages that is not one: not found, never sent to sign in.
    $response = (new AdminBrowser)->get('/admin/sign-in/nothing');
    $response->assertNotFound();

    expect($response->headers->get('Location'))->toBeNull();
});

it('leaves every signed-out page\'s address alone: a mistyped one under them is plain not found, never sent to sign in (stage 5 step 10)', function (string $address) {
    $response = (new AdminBrowser)->get($address);
    $response->assertNotFound();

    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->headers->get('X-Inertia'))->toBeNull()
        ->and($response->getContent())->not->toContain('data-page');
})->with([
    'a password page' => '/admin/password/nothing',
    'a reset with no token' => '/admin/password/reset',
    'an invitation with more after it' => '/admin/invitation/token/code/more',
    'an email change with more after it' => '/admin/email-change/token/more',
    'the language chosen, read' => '/admin/preferences/x',
]);

it('still sends somebody signed out to sign in from an address that only begins like a signed-out page\'s', function () {
    foreach (['/admin/passwords', '/admin/invitations-old', '/admin/preferencesx'] as $address) {
        (new AdminBrowser)->get($address)->assertRedirect('/admin/sign-in');
    }
});

it('names every panel page that is reached signed out among the addresses the fallback leaves alone', function () {
    $signedOut = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        // This file's own test routes are not the panel's pages.
        if (! str_starts_with($uri, 'admin/') || str_starts_with($uri, 'admin/_test/') || $route->isFallback || str_contains($uri, 'fallbackPlaceholder')) {
            continue;
        }

        $guarded = array_filter($route->gatherMiddleware(), static fn (mixed $middleware): bool => is_string($middleware) && str_ends_with($middleware, 'RequireStaff'));

        if ($guarded === []) {
            $signedOut[] = explode('/', substr($uri, strlen('admin/')))[0];
        }
    }

    $signedOut = array_values(array_unique($signedOut));
    sort($signedOut);
    $named = PanelNotFoundController::SIGNED_OUT;
    sort($named);

    expect($signedOut)->toBe($named)
        ->and(PanelNotFoundController::PATHS)->toBe('(?!(?:'.implode('|', PanelNotFoundController::SIGNED_OUT).')(?:/|$)).+');
});

it('leaves a form sent, a mistyped address sent anything but a read, and anybody not signed in on Laravel\'s page, which says no developer message', function () {
    $browser = panelErrorSignedIn();

    foreach ([
        $browser->post('/admin/_test/missing'),
        // Not "method not allowed": not found, as before the panel had its own page for a mistyped address.
        $browser->post('/admin/no-such-page'),
        (new AdminBrowser)->post('/admin/no-such-page'),
        (new AdminBrowser)->get('/admin/_test/signed-out-missing'),
    ] as $response) {
        $response->assertNotFound();

        expect($response->headers->get('X-Inertia'))->toBeNull()
            ->and($response->getContent())->not->toContain('data-page')
            ->and($response->getContent())->not->toContain('Developer note');
    }
});
