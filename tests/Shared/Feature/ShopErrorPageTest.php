<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\StorefrontArea;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\ShopperLine;
use Modules\Access\Public\Contracts\ShopperLines;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\ShopperLineTone;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\head;
use function Pest\Laravel\post;
use function Pest\Laravel\seed;
use function Pest\Laravel\withHeaders;

/*
| The shop's error page (frontend.md §2.6; app/Http/ProblemDetails), the panel's counterpart: a page of a
| store's shop somebody could not have is answered in the shop's frame, in the address's language, with the
| error's translated title and its own sentence - or its status's, in a shopper's words - and the way back to
| the store's home; never the message written for developers. A mistyped address under a store gets it
| through Platform's last shop route (ShopNotFoundController). What stays as it was is proved too: a form
| sent, JSON, an unknown or switched-off store, and the reserved paths.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'app.debug' => false]);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    Route::prefix('{store}/{locale}')
        ->middleware(StorefrontArea::MIDDLEWARE)
        ->group(function (): void {
            Route::get('_test/missing', fn () => abort(404, 'Developer note: no such row in table x.'));
            Route::get('_test/not-yours', fn () => throw new Unauthorized('platform.store.update'));
            Route::get('_test/conflict', fn () => throw shopErrorUntranslated(ErrorCategory::Conflict));
            Route::get('_test/invalid', fn () => throw shopErrorUntranslated(ErrorCategory::Invalid));
            Route::get('_test/unsupported', fn () => throw shopErrorUntranslated(ErrorCategory::Unsupported));
            Route::get('_test/too-large', fn () => throw shopErrorUntranslated(ErrorCategory::TooLarge));
            // A refusal naming the rule it broke (FormErrors::ruleKey): its own words, as a form says them.
            Route::get('_test/rule', fn () => throw new BandsInvalid(BandsInvalid::ORDER));
            Route::post('_test/missing', fn () => abort(404, 'Developer note: no such row in table x.'));
            Route::post('_test/not-yours', fn () => throw new Unauthorized('platform.store.update'));
        });
});

/** A business error of no module's, with no words written for it: its message is a developer's. */
function shopErrorUntranslated(ErrorCategory $category): DomainError
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

it('answers a mistyped shop address with the shop\'s not-found page, in the address\'s language, with the way back to the store', function (string $locale, string $title, string $sentence, string $back) {
    foreach (["/sa/{$locale}/categoriez", "/sa/{$locale}/no/such/page", "/sa/{$locale}/products", "/sa/{$locale}/_test/missing",
        // An address only a form posts to, read: not found, no longer "method not allowed".
        "/sa/{$locale}/account/register"] as $address) {
        $response = get($address);

        $response->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Storefront/Error')
            ->where('status', 404)
            ->where('title', $title)
            ->where('detail', $sentence)
            ->where('locale', $locale)
            ->where('direction', $locale === 'ar' ? 'rtl' : 'ltr')
            ->where('shop.code', 'sa')
            ->where('translations', fn (Collection $words): bool => $words->get('errors.shop.back') === $back
                // The frame's own words travel with it, as on every shop page (StorefrontArea::WORDS).
                && $words->has('access::auth.sign_in')
                && $words->has('platform::stores.placeholder'))
            // Nobody signed in: a guest, as on any shop page; no panel's data.
            ->where('shopper', null)
            ->missing('menu'));

        expect($response->getContent())->not->toContain('Developer note')
            ->and($response->getContent())->toContain('lang="'.$locale.'"');
    }
})->with([
    'English' => ['en', 'Page Not Found', 'This page isn\'t available. Check the address, or go back to the store.', 'Back to the Store'],
    'Arabic' => ['ar', 'الصفحة غير موجودة', 'هذه الصفحة غير متاحة. تحقّق من العنوان، أو عُد إلى المتجر.', 'العودة إلى المتجر'],
]);

it('says each status\'s title and sentence in a shopper\'s words for an error that wrote none - the panel keeping its own titles - never its developers\' message', function (string $locale, string $path, int $status, string $title, string $sentence) {
    $response = get("/sa/{$locale}{$path}");

    $response->assertStatus($status)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Error')
        ->where('status', $status)
        ->where('title', $title)
        ->where('detail', $sentence));

    expect($response->getContent())->not->toContain('Developer note')
        ->and($response->getContent())->not->toContain('testing.nothing_written');
})->with([
    // A page refused says what a missing one says, its status still 403 (owner, 2026-10-20).
    'refused' => ['en', '/_test/not-yours', 403, 'Page Not Found', 'This page isn\'t available. Check the address, or go back to the store.'],
    'a conflict' => ['en', '/_test/conflict', 409, 'Something Changed', 'Couldn\'t open this page: it changed while it loaded. Reload it and try again.'],
    'too large' => ['en', '/_test/too-large', 413, 'File Too Large', 'Couldn\'t upload the file: it\'s too large. Choose a smaller file.'],
    'an unsupported kind' => ['en', '/_test/unsupported', 415, 'File Type Not Accepted', 'Couldn\'t upload the file: this type isn\'t accepted. Choose another file.'],
    'an invalid request' => ['en', '/_test/invalid', 422, 'Check the Details', 'Couldn\'t continue: some details aren\'t valid. Check them and try again.'],
    'refused, in Arabic' => ['ar', '/_test/not-yours', 403, 'الصفحة غير موجودة', 'هذه الصفحة غير متاحة. تحقّق من العنوان، أو عُد إلى المتجر.'],
    'a conflict, in Arabic' => ['ar', '/_test/conflict', 409, 'تغيّر شيء ما', 'تعذّر فتح هذه الصفحة: تغيّرت أثناء تحميلها. أعد تحميلها وحاول مجددًا.'],
    'too large, in Arabic' => ['ar', '/_test/too-large', 413, 'الملف كبير جدًا', 'تعذّر رفع الملف: حجمه كبير جدًا. اختر ملفًا أصغر.'],
    'an unsupported kind, in Arabic' => ['ar', '/_test/unsupported', 415, 'نوع الملف غير مقبول', 'تعذّر رفع الملف: نوعه غير مقبول. اختر ملفًا آخر.'],
    'an invalid request, in Arabic' => ['ar', '/_test/invalid', 422, 'تحقّق من التفاصيل', 'تعذّر المتابعة: بعض التفاصيل غير صالحة. تحقّق منها وحاول مجددًا.'],
]);

it('says the error\'s own sentence when it wrote one, as a form says it', function (string $locale, string $title, string $sentence) {
    get("/sa/{$locale}/_test/rule")->assertStatus(422)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Error')
        ->where('title', $title)
        ->where('detail', $sentence));
})->with([
    'English' => ['en', 'Check the quantity prices', 'Each band must cost less than the band before.'],
    'Arabic' => ['ar', 'تحقّق من أسعار الكميات', 'يجب أن يكون سعر كل شريحة أقل من سعر الشريحة التي قبلها.'],
]);

it('answers a visit made inside the shop, and a HEAD request, with the error page itself', function () {
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    $response = withHeaders($inertia)->get('/sa/en/no-such-page');

    $response->assertNotFound()->assertHeader('X-Inertia', 'true');

    expect($response->json('component'))->toBe('Storefront/Error')
        ->and($response->json('props.detail'))->toBe('This page isn\'t available. Check the address, or go back to the store.')
        ->and($response->json('props.shop.code'))->toBe('sa');

    $head = head('/sa/en/no-such-page');
    $head->assertNotFound();

    expect($head->getContent())->not->toContain('Developer note');
});

it('says no developer message with debug on either', function () {
    config(['app.debug' => true]);

    foreach (['/sa/en/_test/missing', '/sa/en/_test/conflict', '/sa/ar/no-such-page'] as $path) {
        $response = get($path);

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Error'));

        expect($response->getContent())->not->toContain('Developer note')
            ->and($response->getContent())->not->toContain('testing.nothing_written')
            ->and($response->getContent())->not->toContain('NotFoundHttpException');
    }
});

it('leaves a shop page that is there, Catalog\'s own not-found page and the store\'s home as they were', function () {
    get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Platform/Storefront/Home'));
    get('/sa/en/sign-in')->assertOk();
    get('/sa/en/products/no-such-product')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Storefront/NotFound'));
    get('/sa')->assertRedirect();
    get('/')->assertOk();
});

it('leaves a form sent, and anything but a read sent to a mistyped address, on Laravel\'s page - never "method not allowed"', function () {
    foreach ([
        post('/sa/en/_test/missing'),
        post('/sa/en/no-such-page'),
        delete('/sa/ar/no/such/page'),
    ] as $response) {
        $response->assertNotFound();

        expect($response->headers->get('X-Inertia'))->toBeNull()
            ->and($response->getContent())->not->toContain('data-page')
            ->and($response->getContent())->not->toContain('Developer note');
    }

    $refused = post('/sa/en/_test/not-yours');
    $refused->assertForbidden();

    expect($refused->getContent())->not->toContain('data-page');
});

it('answers JSON as before: a problem document, never the page', function () {
    getJson('/sa/en/no-such-page')->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'http.404')
        ->assertJsonPath('title', 'Page Not Found')
        ->assertJsonPath('detail', 'Page Not Found');

    getJson('/sa/en/_test/conflict')->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'testing.nothing_written')
        // The JSON's own fallback sentence is unchanged: the panel's (platform.md §7.4).
        ->assertJsonPath('detail', 'Couldn\'t open this page: something changed meanwhile. Reload it and try again.');

    getJson('/sa/en/search/suggest?q=x')->assertOk()->assertJsonStructure(['products']);
});

it('keeps Laravel\'s page for an unknown store and for a switched-off one, which nobody learns exists', function () {
    DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);
    Cache::flush();

    foreach (['/zz/en/no-such-page', '/eg/en/no-such-page', '/eg/en', '/sa/fr/no-such-page'] as $path) {
        $response = get($path);

        $response->assertNotFound();

        expect($response->headers->get('X-Inertia'))->toBeNull()
            ->and($response->getContent())->not->toContain('data-page');
    }
});

it('never catches the panel, the built assets, the API or the stored files', function () {
    // The panel's own last route: somebody signed out is sent to sign in, as on any panel page.
    get('/admin/en/no-such-page')->assertRedirect('/admin/sign-in');

    foreach ([
        '/build/en/assets/nothing.js' => 404,
        '/api/en/orders' => 404,
        '/up/en/nothing' => 404,
        // Laravel's own route for the local disk's files answers it: refused, as an unsigned link is.
        '/storage/en/nothing.txt' => 403,
    ] as $path => $status) {
        $response = get($path);

        $response->assertStatus($status);

        expect($response->getContent())->not->toContain('data-page')
            ->and($response->getContent())->not->toContain('Storefront/Error');
    }
});

/** A line under the header for a company account, as B2B's is (access.md amendment 50). */
final class ShopErrorPageTestLine implements ShopperLine
{
    public function lineFor(string $customerId, AccountType $accountType, bool $emailVerified): ?ShopperLineDto
    {
        return $accountType === AccountType::Company ? new ShopperLineDto('Finish your application', 'storefront.account', ShopperLineTone::Warn) : null;
    }
}

it('draws the shop\'s error page for a signed-in customer with the whole frame - the name, the account\'s list and the lines - and no second error', function () {
    app(CustomerAccountPages::class)->register(new CustomerAccountPageDto('access', 'test_company', 'storefront.sign-in', AccountType::Company));
    app(ShopperLines::class)->register(ShopErrorPageTestLine::class);
    $customerId = Fx::customer(accountType: 'company');
    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', [
        'email' => (string) DB::table('access.customers')->where('id', $customerId)->value('email'),
        'password' => Fx::CUSTOMER_PASSWORD,
    ])->assertRedirect('/sa/en');

    foreach (['/sa/en/no-such-page' => 404, '/sa/en/account/nothing-here' => 404, '/sa/en/_test/conflict' => 409] as $path => $status) {
        $response = $browser->get($path);

        // The frame's shared data is read while the error page is drawn: had any of it failed, a second
        // error, this would be a 500 rather than the page's own status.
        $response->assertStatus($status)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Storefront/Error')
            ->where('status', $status)
            ->where('shopper.id', $customerId)
            ->has('accountMenu.tabs', 5)
            ->where('accountMenu.pages', fn (Collection $pages): bool => $pages->contains(fn (array $one): bool => $one['key'] === 'access.test_company'))
            ->where('shopperLines', fn (Collection $lines): bool => $lines->contains(fn (array $one): bool => $one['text'] === 'Finish your application'))
            ->where('staffView', null));

        expect($response->getContent())->not->toContain('Developer note');
    }
});

it('draws the shop\'s error page in a Super Admin\'s staff view of a switched-off store, the store marked off', function () {
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    $staffId = Fx::staff(superAdmin: true);
    $browser = new AdminBrowser('10.12.9.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
    $browser->post('/admin/staff-view', ['store' => 'eg'])->assertRedirect('/eg/en');

    $browser->get('/eg/en/no-such-page')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Error')
        ->where('status', 404)
        ->where('detail', 'This page isn\'t available. Check the address, or go back to the store.')
        ->where('shop.code', 'eg')
        ->where('shop.available', fn (Collection $stores): bool => $stores->contains(fn (array $store): bool => $store['code'] === 'eg' && $store['isActive'] === false))
        ->where('staffView.name', fn (string $name): bool => $name !== '')
        ->where('shopper', null));

    // A visitor, the same address: as if the store were never there - Laravel's page, no frame.
    $visitor = (new AdminBrowser)->get('/eg/en/no-such-page');
    $visitor->assertNotFound();

    expect($visitor->getContent())->not->toContain('data-page');
});
