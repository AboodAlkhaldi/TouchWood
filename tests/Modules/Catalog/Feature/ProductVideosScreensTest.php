<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetProductVideos\SetProductVideos;
use Modules\Catalog\Application\Command\SetProductVideos\SetProductVideosHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| flags-2026-11.md S4.4, catalog.md amendment 48, over HTTP: the product's Photos tab gains its Videos (the list in its
| own read, on that tab only) and saves them whole; the shop's product page carries them after the photos, each with
| its title in the page's language - kept with the page in the shop's cache, which a change of them replaces.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

/**
 * Signed in through the panel's door ('*' for All stores).
 *
 * @param  list<string>  $permissions
 */
function productVideosScreen(array $permissions): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, ['*']);
    $browser = new AdminBrowser('10.9.3.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** A ready product on sale in the Saudi store, its videos set as the system. */
function productVideosOnSale(): string
{
    $product = Px::ready()['product'];
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $product, true)));
    Px::onSale('sa', $product);

    return $product;
}

function productVideosSlug(string $product, string $locale): string
{
    return (string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', $locale)->where('is_current', true)->value('slug');
}

describe('the Photos tab', function () {
    it('lists the product\'s videos on the Photos tab only, each with its titles', function () {
        $product = Px::product();
        Fx::asSystem(fn () => app(SetProductVideosHandler::class)->handle(new SetProductVideos($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'تركيب', 'title_en' => 'Fitting']])));
        $browser = productVideosScreen([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);

        $browser->get("/admin/products/{$product}?tab=photos")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/Products/PhotosTab')
            ->where('videos', [['videoId' => 'dQw4w9WgXcQ', 'titleAr' => 'تركيب', 'titleEn' => 'Fitting']]));
        $browser->get("/admin/products/{$product}?tab=details")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('videos', null));
    });

    it('saves the list whole, says so, and refuses a link that is not YouTube\'s beside the videos', function () {
        $product = Px::product();
        $browser = productVideosScreen([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);

        $saved = $browser->post("/admin/products/{$product}/videos", ['videos' => [['link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title_ar' => '', 'title_en' => 'Fitting']]]);

        expect(AdminBrowser::flashed($saved, 'status'))->toBe('Videos saved')
            ->and(DB::table('catalog.product_videos')->where('product_id', $product)->pluck('video_id')->all())->toBe(['dQw4w9WgXcQ']);

        $refused = $browser->post("/admin/products/{$product}/videos", ['videos' => [['link' => 'https://vimeo.com/1']]]);
        $errors = AdminBrowser::flashed($refused, 'errors');
        $messages = $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : (is_array($errors) ? ($errors['default']['messages'] ?? []) : []);

        expect($messages['videos'] ?? null)->toBe(['Paste a YouTube link.'])
            ->and(DB::table('catalog.product_videos')->where('product_id', $product)->pluck('video_id')->all())->toBe(['dQw4w9WgXcQ']);
    });

    it('refuses whoever only reads products, as the gallery does', function () {
        $product = Px::product();
        $browser = productVideosScreen([P::PRODUCT_VIEW]);

        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/videos", ['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ']]])))->toBe(trans('errors.unauthorized.detail', [], 'en'))
            ->and(DB::table('catalog.product_videos')->count())->toBe(0);
    });
});

describe('the shop\'s product page', function () {
    it('carries the videos after the photos, each title in the page\'s language, on a page not available now too', function () {
        $product = productVideosOnSale();
        Fx::asSystem(fn () => app(SetProductVideosHandler::class)->handle(new SetProductVideos($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'تركيب', 'title_en' => 'Fitting'], ['link' => 'https://youtu.be/a-B_c1D2e3F']])));

        get('/sa/en/products/'.productVideosSlug($product, 'en'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('videos', [['id' => 'dQw4w9WgXcQ', 'title' => 'Fitting'], ['id' => 'a-B_c1D2e3F', 'title' => null]]));
        get('/sa/ar/products/'.productVideosSlug($product, 'ar'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('videos', [['id' => 'dQw4w9WgXcQ', 'title' => 'تركيب'], ['id' => 'a-B_c1D2e3F', 'title' => null]]));

        // Not chosen in Egypt: its one "Not available now" page shows its photos and videos still.
        get('/eg/en/products/'.productVideosSlug($product, 'en'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('available', false)
            ->where('videos.0.id', 'dQw4w9WgXcQ'));
    });

    it('shows a change of them at once: the page kept in the cache is replaced', function () {
        $product = productVideosOnSale();
        $url = '/sa/en/products/'.productVideosSlug($product, 'en');

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('videos', []));
        Fx::asSystem(fn () => app(SetProductVideosHandler::class)->handle(new SetProductVideos($product, [['link' => 'https://youtu.be/dQw4w9WgXcQ']])));
        app()->forgetScopedInstances();

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('videos', [['id' => 'dQw4w9WgXcQ', 'title' => null]]));
    });

    it('asks no more of the database warm with videos than without: they are kept with the page', function () {
        $plain = productVideosOnSale();
        $with = productVideosOnSale();
        Fx::asSystem(fn () => app(SetProductVideosHandler::class)->handle(new SetProductVideos($with, [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_en' => 'Fitting']])));
        $count = static function (string $url): int {
            app()->forgetScopedInstances();
            get($url)->assertOk();
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            get($url)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Measured 2026-11-21: 19 each here, with the database session driver this file signs in with (ShopPagesTest's budget, 16, is its own).
        expect($count('/sa/en/products/'.productVideosSlug($with, 'en')))->toBe($count('/sa/en/products/'.productVideosSlug($plain, 'en')))
            ->and($count('/sa/en/products/'.productVideosSlug($with, 'en')))->toBeGreaterThan(0);
    });
});
