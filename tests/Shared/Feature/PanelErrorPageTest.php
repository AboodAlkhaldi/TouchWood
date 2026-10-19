<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

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
            Route::post('_test/missing', fn () => abort(404, 'Developer note: no such row in table x.'));
        });

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

it('says a refusal on permission with its own sentence', function () {
    panelErrorSignedIn()->get('/admin/_test/not-yours')->assertForbidden()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Error')
        ->where('status', 403)
        ->where('title', 'Not Allowed')
        ->where('detail', 'Couldn\'t do that: it isn\'t one of your jobs. Ask an administrator for it.'));
});

it('says the status\'s sentence for an error that wrote none, never its developers\' message', function (string $path, int $status, string $title, string $sentence) {
    $response = panelErrorSignedIn()->get($path);

    $response->assertStatus($status)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Error')
        ->where('status', $status)
        ->where('title', $title)
        ->where('detail', $sentence));

    expect($response->getContent())->not->toContain('Developer note')
        ->and($response->getContent())->not->toContain('testing.nothing_written');
})->with([
    'a conflict' => ['/admin/_test/conflict', 409, 'Conflict', 'Couldn\'t open this page: something changed meanwhile. Reload it and try again.'],
    'an invalid request' => ['/admin/_test/invalid', 422, 'Invalid Request', 'Couldn\'t open this page: something in its address isn\'t valid.'],
]);

it('leaves a form sent, an address no route answers and anybody not signed in on Laravel\'s page, which says no developer message', function () {
    $browser = panelErrorSignedIn();

    foreach ([$browser->post('/admin/_test/missing'), $browser->get('/admin/no-such-page'), (new AdminBrowser)->get('/admin/_test/signed-out-missing')] as $response) {
        $response->assertNotFound();

        expect($response->headers->get('X-Inertia'))->toBeNull()
            ->and($response->getContent())->not->toContain('data-page')
            ->and($response->getContent())->not->toContain('Developer note');
    }
});
