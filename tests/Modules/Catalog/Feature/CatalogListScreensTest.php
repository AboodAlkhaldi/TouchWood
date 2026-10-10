<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\RankCategories\RankCategories;
use Modules\Catalog\Application\Command\RankCategories\RankCategoriesHandler;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's list screens over HTTP (catalog.md §4.4 S1–S7, amendment 13): who opens each page — the
| list's job in any store reads it, only the job with All stores changes it (P2) —, the categories'
| store filter, every form's success and refusal said where the panel says it, a photo uploaded from
| its form (P5), and each page's real number of queries, recorded under the admin budget of 15
| (frontend.md §5, P21).
|
| The use cases and the reads have their own tests; these are about the screens. Every helper is
| named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
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
 * @param  list<string>  $stores
 */
function catalogListScreens(array $permissions, array $stores = ['*']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores);
    $browser = new AdminBrowser('10.9.1.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * The field errors a redirect carries.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function catalogListScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogListScreensToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

/**
 * The menu entry keys the panel offers on a page, as "group/key".
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function catalogListScreensMenu(TestResponse $response): array
{
    $offered = [];

    $response->assertInertia(function (AssertableInertia $page) use (&$offered): void {
        foreach ($page->toArray()['props']['menu'] as $group) {
            foreach ($group['entries'] as $entry) {
                $offered[] = $group['key'].'/'.$entry['key'];
            }
        }
    });

    return $offered;
}

/**
 * How many queries the page asks for itself, opened a second time (warm: the reader's permissions and
 * the store list already in the cache, as frontend.md §5 counts): those made while Catalog's own code
 * is running. The panel's frame around it - the menu, the staff shell, the session - is not counted.
 */
function catalogListScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Catalog/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    // Read at once: the listener stays for the rest of the test.
    return count($own);
}

/** The refusal of a change by someone without the job (with All stores). */
function catalogListScreensNotYours(): string
{
    return trans('errors.unauthorized.detail', [], 'en');
}

function catalogListScreensLabel(string $nameEn = 'New'): string
{
    return Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('جديد', $nameEn, 'green', 10)));
}

/** A description typed with the products file's marks (P1). */
function catalogListScreensMarks(): string
{
    return "Made in Austria.\n\n- Soft close\n- **Lifetime** hinges";
}

describe('the menu', function () {
    it('offers each list in the Catalog group to the holder of its job in any store, and none for another job', function () {
        $menu = catalogListScreensMenu(catalogListScreens([
            P::BRAND_MANAGE, P::CATEGORY_RANK, P::ATTRIBUTE_MANAGE, P::LABEL_MANAGE, P::WARRANTY_MANAGE, P::SEARCH_WORD_MANAGE,
        ], ['eg'])->get('/admin'));

        expect($menu)->toContain('catalog/brands', 'catalog/categories', 'catalog/attributes', 'catalog/variations', 'catalog/labels', 'catalog/warranties', 'catalog/search_words')
            // The tree is offered for either of its jobs: ordering a menu, or changing the tree.
            ->and(catalogListScreensMenu(catalogListScreens([P::CATEGORY_MANAGE], ['sa'])->get('/admin')))->toContain('catalog/categories');

        $other = catalogListScreensMenu(catalogListScreens([P::PRODUCT_VIEW])->get('/admin'));

        expect(array_values(array_filter($other, fn (string $key): bool => in_array($key, [
            'catalog/brands', 'catalog/categories', 'catalog/attributes', 'catalog/variations', 'catalog/labels', 'catalog/warranties', 'catalog/search_words',
        ], true))))->toBe([]);
    });
});

describe('opening a list (P2)', function () {
    it('opens for the list\'s job in one store, read only, and for the job with All stores, to change', function (string $uri, string $job, string $component, string $mayChange) {
        catalogListScreens([$job], ['eg'])->get($uri)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where($mayChange, false));

        catalogListScreens([$job])->get($uri)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where($mayChange, true));
    })->with([
        'brands' => ['/admin/brands', P::BRAND_MANAGE, 'Catalog/Admin/Brands/Index', 'mayChange'],
        'categories' => ['/admin/categories', P::CATEGORY_MANAGE, 'Catalog/Admin/Categories/Index', 'mayManage'],
        'attributes' => ['/admin/attributes', P::ATTRIBUTE_MANAGE, 'Catalog/Admin/Attributes/Index', 'mayChange'],
        'variations' => ['/admin/variations', P::ATTRIBUTE_MANAGE, 'Catalog/Admin/Variations/Index', 'mayChange'],
        'labels' => ['/admin/labels', P::LABEL_MANAGE, 'Catalog/Admin/Labels/Index', 'mayChange'],
        'warranties' => ['/admin/warranties', P::WARRANTY_MANAGE, 'Catalog/Admin/Warranties/Index', 'mayChange'],
        'search words' => ['/admin/search-words', P::SEARCH_WORD_MANAGE, 'Catalog/Admin/SearchWords/Index', 'mayChange'],
    ]);

    it('refuses someone holding none of the list\'s jobs', function (string $uri) {
        catalogListScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE])->get($uri)->assertForbidden();
    })->with([
        'brands' => ['/admin/brands'],
        'categories' => ['/admin/categories'],
        'attributes' => ['/admin/attributes'],
        'variations' => ['/admin/variations'],
        'labels' => ['/admin/labels'],
        'warranties' => ['/admin/warranties'],
        'search words' => ['/admin/search-words'],
    ]);

    it('answers an attribute that does not exist as not found', function () {
        catalogListScreens([P::ATTRIBUTE_MANAGE])->get('/admin/attributes/01hzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    });

    it('opens one attribute read only for the job in one store, and refuses anyone without it', function () {
        $width = Px::attribute('Width');

        catalogListScreens([P::ATTRIBUTE_MANAGE], ['eg'])->get("/admin/attributes/{$width}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Attributes/Show')->where('mayChange', false)->where('attribute.id', $width));

        catalogListScreens([P::LABEL_MANAGE])->get("/admin/attributes/{$width}")->assertForbidden();
    });
});

describe('the brands', function () {
    it('shows each brand with its number, its products, its logo\'s thumbnail and its description as typed', function () {
        $brand = Px::brand('Hettich');
        Px::product('Hinge', $brand);
        DB::table('catalog.brands')->where('id', $brand)->update([
            'logo_media_id' => Cx::media(),
            'description_ar' => json_encode(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'مفصلات', 'bold' => false]]]]]),
            'description_en' => json_encode(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Hinges', 'bold' => true]]]]]),
        ]);

        catalogListScreens([P::BRAND_MANAGE])->get('/admin/brands')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brands.0.id', $brand)
                ->where('brands.0.number', (int) DB::table('catalog.brands')->where('id', $brand)->value('number'))
                ->where('brands.0.products', 1)
                ->where('brands.0.descriptionEn', '**Hinges**')
                ->where('brands.0.logo', fn (?string $url): bool => is_string($url) && str_contains($url, 'thumb'))
                ->where('reached', null)
                ->has('countries.0.code')
            );
    });

    it('offers the countries only to someone who may change the list', function () {
        catalogListScreens([P::BRAND_MANAGE], ['sa'])->get('/admin/brands')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('countries', []));
    });

    it('adds a brand with its description, country and an uploaded logo (P5)', function () {
        $browser = catalogListScreens([P::BRAND_MANAGE]);

        $added = $browser->post('/admin/brands', [
            'name_ar' => 'هيتش', 'name_en' => 'Hettich', 'agency_type' => 'EXCLUSIVE_AGENT', 'position' => '30',
            'show_in_default_listings' => '1', 'origin_country' => 'DE',
            'description_ar' => 'صنع في ألمانيا.', 'description_en' => catalogListScreensMarks(),
            'logo' => UploadedFile::fake()->image('hettich.png', 300, 200),
        ]);
        $row = DB::table('catalog.brands')->where('name_en', 'Hettich')->first();

        expect(catalogListScreensToast($added))->toBe('Brand added')
            ->and($row?->agency_type)->toBe('EXCLUSIVE_AGENT')
            ->and($row?->position)->toBe(30)
            ->and($row?->origin_country)->toBe('DE')
            ->and(json_decode((string) $row?->description_en, true)['blocks'][1]['type'] ?? null)->toBe('list')
            ->and(DB::table('platform.media')->where('id', $row?->logo_media_id)->value('visibility'))->toBe('PUBLIC');
    });

    it('keeps the logo when none is sent, and takes it away when asked', function () {
        $brand = Px::brand('Hettich');
        $logo = Cx::media();
        DB::table('catalog.brands')->where('id', $brand)->update(['logo_media_id' => $logo]);
        $browser = catalogListScreens([P::BRAND_MANAGE]);
        $form = ['name_ar' => 'هيتش', 'name_en' => 'Hettich', 'agency_type' => 'DISTRIBUTOR', 'position' => '10'];

        expect(catalogListScreensToast($browser->post("/admin/brands/{$brand}", [...$form, 'logo_media_id' => $logo])))->toBe('Brand saved')
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('logo_media_id'))->toBe($logo);

        $browser->post("/admin/brands/{$brand}", [...$form, 'logo_media_id' => $logo, 'remove_logo' => '1']);

        expect(DB::table('catalog.brands')->where('id', $brand)->value('logo_media_id'))->toBeNull();
    });

    it('replaces a logo with a new file, and refuses one Platform does not take, keeping nothing', function () {
        $brand = Px::brand('Hettich');
        $old = Cx::media();
        DB::table('catalog.brands')->where('id', $brand)->update(['logo_media_id' => $old]);
        $browser = catalogListScreens([P::BRAND_MANAGE]);
        $form = ['name_ar' => 'هيتش', 'name_en' => 'Hettich', 'agency_type' => 'DISTRIBUTOR', 'position' => '10', 'logo_media_id' => $old];

        expect(catalogListScreensToast($browser->post("/admin/brands/{$brand}", [...$form, 'logo' => UploadedFile::fake()->image('new.png', 200, 200)])))->toBe('Brand saved');

        $new = (string) DB::table('catalog.brands')->where('id', $brand)->value('logo_media_id');
        $media = DB::table('platform.media')->count();

        expect($new)->not->toBe($old)
            ->and(DB::table('platform.media')->where('id', $new)->value('visibility'))->toBe('PUBLIC')
            // A PDF is no logo: Platform refuses it, said at the top, and the brand keeps its logo.
            ->and(AdminBrowser::formError($browser->post("/admin/brands/{$brand}", [...$form, 'logo' => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf')])))
            ->toBe(trans('platform::errors.unsupported_media_type.detail', [], 'en'))
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('logo_media_id'))->toBe($new)
            ->and(DB::table('platform.media')->count())->toBe($media);
    });

    it('says beside the logo that a file did not arrive whole, never a 500', function () {
        $browser = catalogListScreens([P::BRAND_MANAGE]);
        // As PHP hands over a file larger than it accepts: named, with its error, and nothing to read.
        $tooBig = new UploadedFile(UploadedFile::fake()->image('big.png')->getPathname(), 'big.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

        $response = $browser->post('/admin/brands', ['name_ar' => 'بلوم', 'name_en' => 'Blum', 'agency_type' => 'DISTRIBUTOR', 'position' => '10', 'logo' => $tooBig]);

        $response->assertRedirect();
        expect(catalogListScreensErrors($response)['logo_media_id'][0] ?? null)->toBe(trans('catalog::admin.image.not_arrived', [], 'en'))
            ->and(DB::table('catalog.brands')->count())->toBe(0);
    });

    it('says an empty name, a country and a position beside their fields, and a taken address at the top', function () {
        $taken = Px::brand('Hettich');
        $browser = catalogListScreens([P::BRAND_MANAGE]);
        $form = ['name_ar' => 'بلوم', 'name_en' => 'Blum', 'agency_type' => 'DISTRIBUTOR', 'position' => '10'];

        expect(catalogListScreensErrors($browser->post('/admin/brands', [...$form, 'name_ar' => ''])))->toHaveKey('name_ar')
            ->and(catalogListScreensErrors($browser->post('/admin/brands', [...$form, 'origin_country' => 'Germany'])))->toHaveKey('origin_country')
            ->and(catalogListScreensErrors($browser->post('/admin/brands', [...$form, 'position' => 'ten'])))->toHaveKey('position')
            // A brand's name may repeat; its address may not, and the same name makes the same address.
            ->and(AdminBrowser::formError($browser->post('/admin/brands', [...$form, 'name_en' => (string) DB::table('catalog.brands')->where('id', $taken)->value('name_en')])))->toContain('is used, or was used, by another')
            ->and(DB::table('catalog.brands')->count())->toBe(1);
    });

    it('refuses every change to a reader without All stores', function () {
        $brand = Px::brand('Hettich');
        $browser = catalogListScreens([P::BRAND_MANAGE], ['sa']);

        // A logo sent with a refused form is refused before it is uploaded: nothing is left in the library.
        expect(AdminBrowser::formError($browser->post('/admin/brands', ['name_ar' => 'بلوم', 'name_en' => 'Blum', 'agency_type' => 'DISTRIBUTOR', 'position' => '10', 'logo' => UploadedFile::fake()->image('blum.png', 200, 200)])))->toBe(catalogListScreensNotYours())
            ->and(DB::table('platform.media')->count())->toBe(0)
            ->and(AdminBrowser::formError($browser->post("/admin/brands/{$brand}/deactivate", ['every_product' => 'HIDE'])))->toBe(catalogListScreensNotYours())
            ->and(AdminBrowser::formError($browser->post("/admin/brands/{$brand}/delete")))->toBe(catalogListScreensNotYours())
            ->and(DB::table('catalog.brands')->count())->toBe(1)
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('is_active'))->toBeTrue();
    });

    it('reads the products a deactivation reaches for its dialog, only for someone who may deactivate', function () {
        $brand = Px::brand('Hettich');
        $product = Px::product('Hinge', $brand);

        catalogListScreens([P::BRAND_MANAGE])->get("/admin/brands?reach={$brand}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reachedFor', $brand)
                ->has('reached', 1)
                ->where('reached.0.id', $product)
                ->where('reached.0.stage', 'DRAFT')
            );

        catalogListScreens([P::BRAND_MANAGE], ['sa'])->get("/admin/brands?reach={$brand}")->assertForbidden();
    });

    it('deactivates a brand with each product\'s fate, says a missing target beside it, and activates it again', function () {
        // The first brand made is the default, which never goes (§1.6).
        $other = Px::brand('Blum');
        $brand = Px::brand('Hettich');
        $hidden = Px::product('Hinge', $brand);
        $moved = Px::product('Slide', $brand);
        $browser = catalogListScreens([P::BRAND_MANAGE]);

        expect(catalogListScreensErrors($browser->post("/admin/brands/{$brand}/deactivate", ['every_product' => 'MOVE', 'move_to' => ''])))->toHaveKey('move_to')
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('is_active'))->toBeTrue();

        $deactivated = $browser->post("/admin/brands/{$brand}/deactivate", [
            'every_product' => 'HIDE',
            'products' => [$moved => ['choice' => 'MOVE', 'move_to' => $other]],
        ]);

        expect(catalogListScreensToast($deactivated))->toBe('Brand deactivated')
            ->and(DB::table('catalog.products')->where('id', $hidden)->value('hidden_by_brand'))->toBeTrue()
            ->and(DB::table('catalog.products')->where('id', $moved)->value('brand_id'))->toBe($other)
            ->and(catalogListScreensToast($browser->post("/admin/brands/{$brand}/activate")))->toBe('Brand activated')
            ->and(DB::table('catalog.products')->where('id', $hidden)->value('hidden_by_brand'))->toBeFalse();
    });

    it('makes another brand the default, and deletes one no product carries', function () {
        $unused = Px::brand('Blum');
        $brand = Px::brand('Hettich');
        $browser = catalogListScreens([P::BRAND_MANAGE]);

        expect(DB::table('catalog.brands')->where('id', $unused)->value('is_default'))->toBeTrue()
            ->and(AdminBrowser::formError($browser->post("/admin/brands/{$unused}/delete")))->toBe(trans('catalog::errors.default_brand_required.detail', [], 'en'))
            ->and(catalogListScreensToast($browser->post("/admin/brands/{$brand}/default")))->toBe('Default brand changed')
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('is_default'))->toBeTrue()
            ->and(catalogListScreensToast($browser->post("/admin/brands/{$unused}/delete")))->toBe('Brand deleted')
            ->and(DB::table('catalog.brands')->where('id', $unused)->exists())->toBeFalse();
    });
});

describe('the categories', function () {
    it('shows the tree with each one\'s products here and below, and the place in the store shown beside the base store\'s', function () {
        $top = Px::category('Kitchens');
        $low = Px::category('Drawers', $top);
        Px::ready(['60 cm'], $low);
        Fx::asSystem(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$top => 7])));

        catalogListScreens([P::CATEGORY_RANK], ['eg'])->get('/admin/categories')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storeCode', 'eg')
                ->where('storeName', 'Egypt')
                ->where('mayRank', true)
                ->where('mayManage', false)
                ->has('stores', 1)
                ->where('categories', fn (Collection $categories): bool => $categories->contains(fn (array $row): bool => $row['id'] === $top && $row['storeRank'] === 7 && $row['products'] === 1 && $row['productsHere'] === 0)
                    && $categories->contains(fn (array $row): bool => $row['id'] === $low && $row['parentId'] === $top && $row['productsHere'] === 1))
            );
    });

    it('shows the store asked for in the address when the reader orders its menu, and refuses any other', function () {
        Px::category('Kitchens');
        $browser = catalogListScreens([P::CATEGORY_RANK], ['eg']);

        $browser->get('/admin/categories?store=eg')->assertOk();
        // Another store, on or not, or none at all: the same refusal (frontend.md §1.10).
        $browser->get('/admin/categories?store=sa')->assertForbidden();
        $browser->get('/admin/categories?store=zz')->assertForbidden();

        // Ordering every store's menu is not changing the tree.
        catalogListScreens([P::CATEGORY_RANK])->get('/admin/categories?store=sa')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'sa')->where('mayRank', true)->where('mayManage', false));
    });

    it('shows no store to someone who manages the tree but orders no menu', function () {
        catalogListScreens([P::CATEGORY_MANAGE])->get('/admin/categories')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', null)->where('stores', [])->where('mayRank', false)->where('mayManage', true));
    });

    it('adds a top category with an uploaded photo and a sub-category under it, and says what is wrong beside its field', function () {
        $browser = catalogListScreens([P::CATEGORY_MANAGE]);

        $added = $browser->post('/admin/categories', ['name_ar' => 'مطابخ', 'name_en' => 'Kitchens', 'rank' => '10', 'image' => UploadedFile::fake()->image('kitchen.jpg', 400, 300)]);
        $top = DB::table('catalog.categories')->where('name_en', 'Kitchens')->first();

        expect(catalogListScreensToast($added))->toBe('Category added')
            ->and(DB::table('platform.media')->where('id', $top?->image_media_id)->value('visibility'))->toBe('PUBLIC')
            ->and(catalogListScreensToast($browser->post('/admin/categories', ['name_ar' => 'أدراج', 'name_en' => 'Drawers', 'parent_id' => $top?->id, 'rank' => '10'])))->toBe('Category added')
            ->and(DB::table('catalog.categories')->where('name_en', 'Drawers')->value('parent_id'))->toBe($top?->id)
            ->and(catalogListScreensErrors($browser->post('/admin/categories', ['name_ar' => 'أبواب', 'name_en' => '', 'rank' => '10'])))->toHaveKey('name_en')
            ->and(catalogListScreensErrors($browser->post('/admin/categories', ['name_ar' => 'أبواب', 'name_en' => 'Doors'])))->toHaveKey('rank');
    });

    it('renames a category and moves it under another, and refuses a move under itself at the top', function () {
        $kitchens = Px::category('Kitchens');
        $doors = Px::category('Doors');
        $browser = catalogListScreens([P::CATEGORY_MANAGE]);

        expect(catalogListScreensToast($browser->post("/admin/categories/{$doors}", ['name_ar' => 'أبواب', 'name_en' => 'Cabinet doors'])))->toBe('Category saved')
            ->and(DB::table('catalog.categories')->where('id', $doors)->value('name_en'))->toBe('Cabinet doors')
            ->and(catalogListScreensToast($browser->post("/admin/categories/{$doors}/move", ['parent_id' => $kitchens, 'rank' => '20'])))->toBe('Category moved')
            ->and(DB::table('catalog.categories')->where('id', $doors)->value('parent_id'))->toBe($kitchens)
            ->and(AdminBrowser::formError($browser->post("/admin/categories/{$kitchens}/move", ['parent_id' => $doors, 'rank' => '20'])))->toBe(trans('catalog::errors.category_loop.detail', [], 'en'))
            ->and(DB::table('catalog.categories')->where('id', $kitchens)->value('parent_id'))->toBeNull();
    });

    it('saves a menu order in the store the page sent, and refuses no store or one the reader does not order', function () {
        $kitchens = Px::category('Kitchens');
        $doors = Px::category('Doors');
        $browser = catalogListScreens([P::CATEGORY_RANK], ['eg']);
        $ranks = [$doors => '10', $kitchens => '20'];
        $places = fn (string $store): array => DB::table('catalog.store_category_ranks')->where('store_id', Fx::storeId($store))->orderBy('category_id')->pluck('rank', 'category_id')->all();
        $saBefore = $places('sa');
        $egBefore = $places('eg');

        expect(AdminBrowser::formError($browser->post('/admin/categories/order', ['ranks' => $ranks])))->toBe(catalogListScreensNotYours())
            ->and(AdminBrowser::formError($browser->post('/admin/categories/order', ['store' => 'sa', 'ranks' => $ranks])))->toBe(catalogListScreensNotYours())
            ->and($places('eg'))->toBe($egBefore)
            ->and(catalogListScreensToast($browser->post('/admin/categories/order', ['store' => 'eg', 'ranks' => $ranks])))->toBe('Order saved')
            ->and($places('eg')[$doors] ?? null)->toBe(10)
            ->and($places('eg')[$kitchens] ?? null)->toBe(20)
            ->and($places('sa'))->toBe($saBefore);
    });

    it('reads the products a category\'s deactivation reaches, in it and under it, only for someone who may deactivate', function () {
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        ['product' => $product] = Px::ready(['60 cm'], $drawers);

        catalogListScreens([P::CATEGORY_MANAGE])->get("/admin/categories?reach={$kitchens}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reachedFor', $kitchens)->has('reached', 1)->where('reached.0.id', $product)->where('reached.0.categoryId', $drawers));

        catalogListScreens([P::CATEGORY_MANAGE], ['sa'])->get("/admin/categories?reach={$kitchens}")->assertForbidden();
    });

    it('hides a category\'s products but moves one elsewhere, and says a product\'s own move without a target', function () {
        $drawers = Px::category('Drawers');
        $sinks = Px::category('Sinks');
        ['product' => $hidden] = Px::ready(['60 cm'], $drawers);
        ['product' => $moved] = Px::ready(['80 cm'], $drawers);
        $browser = catalogListScreens([P::CATEGORY_MANAGE]);

        expect(catalogListScreensErrors($browser->post("/admin/categories/{$drawers}/deactivate", ['every_product' => 'HIDE', 'products' => [$moved => ['choice' => 'MOVE', 'move_to' => '']]])))->toHaveKey('move_to')
            ->and(DB::table('catalog.categories')->where('id', $drawers)->value('is_active'))->toBeTrue()
            ->and(catalogListScreensToast($browser->post("/admin/categories/{$drawers}/deactivate", ['every_product' => 'HIDE', 'products' => [$moved => ['choice' => 'MOVE', 'move_to' => $sinks]]])))->toBe('Category deactivated')
            ->and(DB::table('catalog.products')->where('id', $hidden)->value('hidden_by_category'))->toBeTrue()
            ->and(DB::table('catalog.products')->where('id', $moved)->first(['category_id', 'hidden_by_category']))->toEqual((object) ['category_id' => $sinks, 'hidden_by_category' => false]);
    });

    it('gives a category a new photo and takes it away', function () {
        $kitchens = Px::category('Kitchens');
        $browser = catalogListScreens([P::CATEGORY_MANAGE]);
        $form = ['name_ar' => 'مطابخ', 'name_en' => 'Kitchens'];

        expect(catalogListScreensToast($browser->post("/admin/categories/{$kitchens}", [...$form, 'image' => UploadedFile::fake()->image('kitchen.jpg', 300, 200)])))->toBe('Category saved');

        $photo = DB::table('catalog.categories')->where('id', $kitchens)->value('image_media_id');

        expect($photo)->not->toBeNull()
            ->and(catalogListScreensToast($browser->post("/admin/categories/{$kitchens}", [...$form, 'image_media_id' => $photo, 'remove_image' => '1'])))->toBe('Category saved')
            ->and(DB::table('catalog.categories')->where('id', $kitchens)->value('image_media_id'))->toBeNull();
    });

    it('deactivates a category leaving its products in it, says a missing target beside it, activates it, and deletes an empty one', function () {
        $drawers = Px::category('Drawers');
        $empty = Px::category('Sinks');
        ['product' => $product] = Px::ready(['60 cm'], $drawers);
        $browser = catalogListScreens([P::CATEGORY_MANAGE]);

        expect(catalogListScreensErrors($browser->post("/admin/categories/{$drawers}/deactivate", ['every_product' => 'MOVE'])))->toHaveKey('move_to')
            ->and(catalogListScreensErrors($browser->post("/admin/categories/{$drawers}/deactivate", ['every_product' => 'SELL'])))->toHaveKey('choice')
            ->and(catalogListScreensToast($browser->post("/admin/categories/{$drawers}/deactivate", ['every_product' => 'LEAVE'])))->toBe('Category deactivated')
            ->and(DB::table('catalog.categories')->where('id', $drawers)->value('is_active'))->toBeFalse()
            ->and(DB::table('catalog.products')->where('id', $product)->first(['category_id', 'hidden_by_category']))->toEqual((object) ['category_id' => $drawers, 'hidden_by_category' => false])
            ->and(catalogListScreensToast($browser->post("/admin/categories/{$drawers}/activate")))->toBe('Category activated')
            ->and(catalogListScreensToast($browser->post("/admin/categories/{$empty}/delete")))->toBe('Category deleted')
            ->and(DB::table('catalog.categories')->where('id', $empty)->exists())->toBeFalse()
            ->and(AdminBrowser::formError($browser->post("/admin/categories/{$drawers}/delete")))->toBe(trans('catalog::errors.category_not_empty.detail', [], 'en'));
    });
});

describe('a reader without All stores (P2)', function () {
    it('is refused every change, with nothing changed and no photo kept', function (string $job, Closure $request) {
        [$uri, $data] = $request();
        $after = fn (): array => [DB::table('catalog.categories')->count(), DB::table('catalog.attributes')->count(), DB::table('catalog.attribute_values')->count(), DB::table('catalog.attribute_sets')->count(), DB::table('catalog.warranties')->count(), DB::table('catalog.word_pairs')->count()];
        $before = $after();

        expect(AdminBrowser::formError(catalogListScreens([$job], ['sa', 'eg'])->post($uri, $data)))->toBe(catalogListScreensNotYours())
            ->and($after())->toBe($before)
            ->and(DB::table('platform.media')->count())->toBe(0);
    })->with([
        'add a category, with a photo' => [P::CATEGORY_MANAGE, fn (): array => ['/admin/categories', ['name_ar' => 'مطابخ', 'name_en' => 'Kitchens', 'rank' => '10', 'image' => UploadedFile::fake()->image('k.jpg')]]],
        'edit a category' => [P::CATEGORY_MANAGE, fn (): array => ['/admin/categories/'.Px::category('Kitchens'), ['name_ar' => 'مطابخ', 'name_en' => 'Cabinets']]],
        'move a category' => [P::CATEGORY_MANAGE, fn (): array => ['/admin/categories/'.Px::category('Doors').'/move', ['parent_id' => Px::category('Kitchens'), 'rank' => '10']]],
        'deactivate a category' => [P::CATEGORY_MANAGE, fn (): array => ['/admin/categories/'.Px::category('Kitchens').'/deactivate', ['every_product' => 'HIDE']]],
        'delete a category' => [P::CATEGORY_MANAGE, fn (): array => ['/admin/categories/'.Px::category('Kitchens').'/delete', []]],
        'add an attribute' => [P::ATTRIBUTE_MANAGE, fn (): array => ['/admin/attributes', ['name_ar' => 'العرض', 'name_en' => 'Width', 'kind' => 'VARIANT', 'position' => '10']]],
        'edit an attribute' => [P::ATTRIBUTE_MANAGE, fn (): array => ['/admin/attributes/'.Px::attribute('Width'), ['name_ar' => 'العرض', 'name_en' => 'Breadth', 'kind' => 'VARIANT', 'position' => '10']]],
        'edit a value' => [P::ATTRIBUTE_MANAGE, fn (): array => ['/admin/attribute-values/'.Px::value(Px::attribute('Width'), '60 cm'), ['name_ar' => 'ستون', 'name_en' => '600 mm', 'position' => '5']]],
        'add a variation' => [P::ATTRIBUTE_MANAGE, fn (): array => ['/admin/variations', ['name_ar' => 'مقاسات', 'name_en' => 'Sizes', 'attribute_ids' => [Px::attribute('Width')]]]],
        'add a warranty' => [P::WARRANTY_MANAGE, fn (): array => ['/admin/warranties', ['name_ar' => 'ضمان', 'name_en' => 'Lifetime', 'terms_ar' => 'الشروط.', 'terms_en' => 'Terms.', 'lifetime' => '1']]],
        'add a word pair' => [P::SEARCH_WORD_MANAGE, fn (): array => ['/admin/search-words', ['word_a' => 'rail', 'word_b' => 'runner']]],
        'delete a word pair' => [P::SEARCH_WORD_MANAGE, fn (): array => ['/admin/search-words/'.Fx::asSystem(fn (): string => app(AddWordPairHandler::class)->handle(new AddWordPair('rail', 'runner'))).'/delete', []]],
    ]);
});

describe('the attributes and their values', function () {
    it('adds and edits an attribute, and says a unit in one language only beside it', function () {
        $browser = catalogListScreens([P::ATTRIBUTE_MANAGE]);
        $form = ['name_ar' => 'العرض', 'name_en' => 'Width', 'kind' => 'VARIANT', 'unit_ar' => 'سم', 'unit_en' => 'cm', 'position' => '10'];

        expect(catalogListScreensToast($browser->post('/admin/attributes', $form)))->toBe('Attribute added')
            ->and(catalogListScreensErrors($browser->post('/admin/attributes', [...$form, 'name_en' => 'Depth', 'unit_ar' => ''])))->toHaveKey('unit_ar');

        $width = (string) DB::table('catalog.attributes')->where('name_en', 'Width')->value('id');

        expect(catalogListScreensToast($browser->post("/admin/attributes/{$width}", [...$form, 'kind' => 'FILTERABLE', 'position' => '20'])))->toBe('Attribute saved')
            ->and(DB::table('catalog.attributes')->where('id', $width)->first(['kind', 'position']))->toEqual((object) ['kind' => 'FILTERABLE', 'position' => 20]);
    });

    it('shows one attribute with its values, and adds a colour value with its swatch', function () {
        $colour = Fx::asSystem(fn (): string => app(AddAttributeHandler::class)->handle(new AddAttribute('اللون', 'Colour', 'VARIANT', isColour: true)));
        $browser = catalogListScreens([P::ATTRIBUTE_MANAGE]);

        expect(catalogListScreensErrors($browser->post("/admin/attributes/{$colour}/values", ['name_ar' => 'أبيض', 'name_en' => 'White', 'swatch' => 'white', 'position' => '10'])))->toHaveKey('swatch')
            ->and(catalogListScreensToast($browser->post("/admin/attributes/{$colour}/values", ['name_ar' => 'أبيض', 'name_en' => 'White', 'swatch' => '#ffffff', 'position' => '10'])))->toBe('Value added');

        $browser->get("/admin/attributes/{$colour}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/Admin/Attributes/Show')
                ->where('attribute.id', $colour)
                ->where('attribute.isColour', true)
                ->where('values.0.nameEn', 'White')
                ->where('values.0.swatch', '#ffffff')
                ->where('values.0.inUse', false)
            );
    });

    it('edits, deactivates, activates and deletes a value', function () {
        $width = Px::attribute('Width');
        $value = Px::value($width, '60 cm');
        $browser = catalogListScreens([P::ATTRIBUTE_MANAGE]);

        expect(catalogListScreensToast($browser->post("/admin/attribute-values/{$value}", ['name_ar' => '٦٠ سم', 'name_en' => '600 mm', 'position' => '5'])))->toBe('Value saved')
            ->and(DB::table('catalog.attribute_values')->where('id', $value)->value('name_en'))->toBe('600 mm')
            ->and(catalogListScreensToast($browser->post("/admin/attribute-values/{$value}/deactivate")))->toBe('Value deactivated')
            ->and(DB::table('catalog.attribute_values')->where('id', $value)->value('is_active'))->toBeFalse()
            ->and(catalogListScreensToast($browser->post("/admin/attribute-values/{$value}/activate")))->toBe('Value activated')
            ->and(catalogListScreensToast($browser->post("/admin/attribute-values/{$value}/delete")))->toBe('Value deleted')
            ->and(DB::table('catalog.attribute_values')->where('id', $value)->exists())->toBeFalse();
    });

    it('deactivates and activates an attribute, and deleting one goes back to the list', function () {
        $width = Px::attribute('Width');
        $browser = catalogListScreens([P::ATTRIBUTE_MANAGE]);

        expect(catalogListScreensToast($browser->post("/admin/attributes/{$width}/deactivate")))->toBe('Attribute deactivated')
            ->and(catalogListScreensToast($browser->post("/admin/attributes/{$width}/activate")))->toBe('Attribute activated');

        $deleted = $browser->post("/admin/attributes/{$width}/delete");

        $deleted->assertRedirect('/admin/attributes');
        expect(catalogListScreensToast($deleted))->toBe('Attribute deleted')
            ->and(DB::table('catalog.attributes')->where('id', $width)->exists())->toBeFalse();
    });

    it('refuses a change to a reader without All stores', function () {
        $width = Px::attribute('Width');
        $browser = catalogListScreens([P::ATTRIBUTE_MANAGE], ['sa']);

        expect(AdminBrowser::formError($browser->post("/admin/attributes/{$width}/values", ['name_ar' => 'قيمة', 'name_en' => '60 cm', 'position' => '10'])))->toBe(catalogListScreensNotYours())
            ->and(AdminBrowser::formError($browser->post("/admin/attributes/{$width}/delete")))->toBe(catalogListScreensNotYours())
            ->and(DB::table('catalog.attribute_values')->count())->toBe(0)
            ->and(DB::table('catalog.attributes')->where('id', $width)->exists())->toBeTrue();
    });
});

describe('the variations', function () {
    it('adds a variation of attributes in order, says none chosen beside it, edits it and takes it out of the list', function () {
        $width = Px::attribute('Width');
        $height = Px::attribute('Height');
        $browser = catalogListScreens([P::ATTRIBUTE_MANAGE]);

        expect(catalogListScreensErrors($browser->post('/admin/variations', ['name_ar' => 'مقاسات', 'name_en' => 'Sizes', 'attribute_ids' => []])))->toHaveKey('attribute_ids')
            ->and(catalogListScreensToast($browser->post('/admin/variations', ['name_ar' => 'مقاسات', 'name_en' => 'Sizes', 'attribute_ids' => [$height, $width]])))->toBe('Variation added');

        $set = (string) DB::table('catalog.attribute_sets')->where('name_en', 'Sizes')->value('id');

        $browser->get('/admin/variations')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('variations.0.attributeIds', [$height, $width])->where('variations.0.builtOn', false)->has('attributes', 2));

        expect(catalogListScreensToast($browser->post("/admin/variations/{$set}", ['name_ar' => 'مقاسات', 'name_en' => 'Sizes', 'attribute_ids' => [$width]])))->toBe('Variation saved')
            ->and(DB::table('catalog.attribute_set_members')->where('attribute_set_id', $set)->pluck('attribute_id')->all())->toBe([$width])
            ->and(catalogListScreensToast($browser->post("/admin/variations/{$set}/deactivate")))->toBe('Variation deactivated')
            ->and(catalogListScreensToast($browser->post("/admin/variations/{$set}/activate")))->toBe('Variation activated')
            ->and(catalogListScreensToast($browser->post("/admin/variations/{$set}/delete")))->toBe('Variation deleted')
            ->and(DB::table('catalog.attribute_sets')->where('id', $set)->exists())->toBeFalse();
    });
});

describe('the labels', function () {
    it('adds a label with its look, says a look Geist has not beside it, edits, deactivates, activates and deletes it', function () {
        $browser = catalogListScreens([P::LABEL_MANAGE]);
        $form = ['name_ar' => 'جديد', 'name_en' => 'New', 'tone' => 'green', 'position' => '10'];

        expect(catalogListScreensErrors($browser->post('/admin/labels', [...$form, 'tone' => 'pink'])))->toHaveKey('tone')
            ->and(catalogListScreensToast($browser->post('/admin/labels', $form)))->toBe('Label added');

        $label = (string) DB::table('catalog.labels')->where('name_en', 'New')->value('id');

        $browser->get('/admin/labels')->assertInertia(fn (AssertableInertia $page) => $page->where('labels.0.tone', 'green')->where('labels.0.products', 0));

        expect(catalogListScreensToast($browser->post("/admin/labels/{$label}", [...$form, 'tone' => 'red-subtle'])))->toBe('Label saved')
            ->and(DB::table('catalog.labels')->where('id', $label)->value('tone'))->toBe('red-subtle')
            ->and(catalogListScreensToast($browser->post("/admin/labels/{$label}/deactivate")))->toBe('Label deactivated')
            ->and(catalogListScreensToast($browser->post("/admin/labels/{$label}/activate")))->toBe('Label activated')
            ->and(catalogListScreensToast($browser->post("/admin/labels/{$label}/delete")))->toBe('Label deleted')
            ->and(DB::table('catalog.labels')->count())->toBe(0);
    });

    it('refuses a change to a reader without All stores', function () {
        $label = catalogListScreensLabel();

        expect(AdminBrowser::formError(catalogListScreens([P::LABEL_MANAGE], ['sa'])->post("/admin/labels/{$label}/delete")))->toBe(catalogListScreensNotYours())
            ->and(DB::table('catalog.labels')->where('id', $label)->exists())->toBeTrue();
    });
});

describe('the warranties', function () {
    it('adds a warranty for life and one for months, with their terms as typed, and says a period out of range beside it', function () {
        $browser = catalogListScreens([P::WARRANTY_MANAGE]);
        $form = ['name_ar' => 'ضمان', 'name_en' => 'Lifetime', 'terms_ar' => 'الشروط.', 'terms_en' => catalogListScreensMarks()];

        expect(catalogListScreensToast($browser->post('/admin/warranties', [...$form, 'lifetime' => '1', 'period_months' => '12'])))->toBe('Warranty added')
            ->and(DB::table('catalog.warranties')->where('name_en', 'Lifetime')->value('period_months'))->toBeNull()
            ->and(catalogListScreensErrors($browser->post('/admin/warranties', [...$form, 'name_en' => 'Two years', 'period_months' => '0'])))->toHaveKey('period_months')
            ->and(catalogListScreensToast($browser->post('/admin/warranties', [...$form, 'name_en' => 'Two years', 'period_months' => '24'])))->toBe('Warranty added')
            ->and(DB::table('catalog.warranties')->where('name_en', 'Two years')->value('period_months'))->toBe(24);

        $browser->get('/admin/warranties')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('warranties', fn (Collection $rows): bool => $rows->contains(fn (array $row): bool => $row['nameEn'] === 'Lifetime' && $row['periodMonths'] === null && $row['termsEn'] === catalogListScreensMarks())));
    });

    it('edits, deactivates, activates and deletes a warranty', function () {
        $warranty = Px::warranty();
        $browser = catalogListScreens([P::WARRANTY_MANAGE]);

        expect(catalogListScreensToast($browser->post("/admin/warranties/{$warranty}", ['name_ar' => 'ضمان', 'name_en' => 'Five years', 'terms_ar' => 'الشروط.', 'terms_en' => 'Terms.', 'period_months' => '60'])))->toBe('Warranty saved')
            ->and(DB::table('catalog.warranties')->where('id', $warranty)->value('period_months'))->toBe(60)
            ->and(catalogListScreensToast($browser->post("/admin/warranties/{$warranty}/deactivate")))->toBe('Warranty deactivated')
            ->and(catalogListScreensToast($browser->post("/admin/warranties/{$warranty}/activate")))->toBe('Warranty activated')
            ->and(catalogListScreensToast($browser->post("/admin/warranties/{$warranty}/delete")))->toBe('Warranty deleted')
            ->and(DB::table('catalog.warranties')->count())->toBe(0);
    });
});

describe('the search words', function () {
    it('adds a word pair, says the same word twice beside it, and deletes it', function () {
        $browser = catalogListScreens([P::SEARCH_WORD_MANAGE]);

        expect(catalogListScreensErrors($browser->post('/admin/search-words', ['word_a' => 'مفصلة', 'word_b' => 'مفصلة'])))->toHaveKey('word_b')
            ->and(catalogListScreensToast($browser->post('/admin/search-words', ['word_a' => 'مفصلة', 'word_b' => 'hinge'])))->toBe('Word pair added');

        $pair = (string) DB::table('catalog.word_pairs')->value('id');

        expect(catalogListScreensToast($browser->post("/admin/search-words/{$pair}/delete")))->toBe('Word pair deleted')
            ->and(DB::table('catalog.word_pairs')->count())->toBe(0);
    });

    it('shows the searches that found nothing to the job with All stores, any store or one, a page at a time', function () {
        $sa = Fx::storeId('sa');
        $eg = Fx::storeId('eg');
        DB::table('catalog.search_log')->insert([
            ['store_id' => $sa, 'locale' => 'ar', 'query' => 'مفصله', 'results' => 0, 'searched_at' => now()->subDay()],
            ['store_id' => $sa, 'locale' => 'ar', 'query' => 'مفصله', 'results' => 0, 'searched_at' => now()],
            ['store_id' => $eg, 'locale' => 'en', 'query' => 'rail', 'results' => 0, 'searched_at' => now()],
            ['store_id' => $eg, 'locale' => 'en', 'query' => 'drawer', 'results' => 4, 'searched_at' => now()],
        ]);
        $browser = catalogListScreens([P::SEARCH_WORD_MANAGE]);

        $browser->get('/admin/search-words')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('searches', 2)
                ->where('searches.0.query', 'مفصله')
                ->where('searches.0.times', 2)
                ->where('searches.0.storeName', 'Saudi Arabia')
                ->where('storeCode', null)
                ->where('more', false)
            );

        $browser->get('/admin/search-words?store=eg')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('searches', 1)->where('searches.0.query', 'rail')->where('storeCode', 'eg')->where('storeTimezone', 'Africa/Cairo'));

        $browser->get('/admin/search-words?store=zz')->assertForbidden();
    });

    it('shows the searches fifty at a time, the next page on asking', function () {
        $sa = Fx::storeId('sa');
        DB::table('catalog.search_log')->insert(array_map(fn (int $n): array => ['store_id' => $sa, 'locale' => 'en', 'query' => "word {$n}", 'results' => 0, 'searched_at' => now()->subMinutes($n)], range(1, 51)));
        $browser = catalogListScreens([P::SEARCH_WORD_MANAGE]);

        $browser->get('/admin/search-words')->assertInertia(fn (AssertableInertia $page) => $page->has('searches', 50)->where('more', true)->where('page', 1));
        $browser->get('/admin/search-words?page=2')->assertInertia(fn (AssertableInertia $page) => $page->has('searches', 1)->where('more', false)->where('page', 2)->where('searches.0.storeCode', 'sa'));
    });

    it('shows the pairs but not the searches to a reader without All stores', function () {
        Fx::asSystem(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge')));

        catalogListScreens([P::SEARCH_WORD_MANAGE], ['sa'])->get('/admin/search-words')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('pairs', 1)->where('searches', null)->where('mayChange', false));
    });
});

describe('the query budget (frontend.md §5, P21)', function () {
    /*
    | What each page asks for itself - its reads, its permission checks, Platform's photos and stores -
    | counted warm and recorded exactly, so a page that grows fails even under 15.
    |
    | The panel's own frame is counted apart and is not Catalog's: the menu checking each entry's job,
    | the staff shell, the stores, the settings, the session. Measured on 2026-10-07 with the reader
    | below, a whole list page was 89 to 100 queries: 52 of them the menu's 26 checks, each reading the
    | reader's grants from the cache table again (Access's CachedGrantsReader keeps nothing for the
    | request; Catalog's seven entries are 7 of those checks), and about 34 the rest of the frame - over
    | the 15 on its own, on every admin page. Brought to the owner, as P21 says.
    */
    it('opens each list page in its own recorded number of queries, warm, however many rows it shows', function (string $page, int $recorded) {
        $brand = Px::brand('Hettich');
        $top = Px::category('Kitchens');
        ['width' => $width] = Px::ready(['60 cm', '80 cm'], Px::category('Drawers', $top));
        Px::product('Hinge', $brand);
        Px::warranty();
        catalogListScreensLabel();
        Fx::asSystem(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge')));
        DB::table('catalog.brands')->where('id', $brand)->update(['logo_media_id' => Cx::media()]);
        DB::table('catalog.search_log')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'query' => 'rail', 'results' => 0, 'searched_at' => now()]);

        $browser = catalogListScreens([
            P::BRAND_MANAGE, P::CATEGORY_MANAGE, P::CATEGORY_RANK, P::ATTRIBUTE_MANAGE, P::LABEL_MANAGE, P::WARRANTY_MANAGE, P::SEARCH_WORD_MANAGE,
        ]);
        $uri = str_replace('{width}', $width, $page);
        $own = catalogListScreensOwnQueries($browser, $uri);

        expect($own)->toBe($recorded)->and($own)->toBeLessThanOrEqual(15);

        // Five more of everything the pages show - a logo each, a sub-category each, values,
        // variations, labels, warranties, pairs, searches in two stores: never one query a row.
        foreach (range(1, 5) as $n) {
            $more = Px::brand("More {$n}");
            DB::table('catalog.brands')->where('id', $more)->update(['logo_media_id' => Cx::media()]);
            Px::category("Below {$n}", Px::category("More {$n}"));
            Px::value($width, "{$n}0 mm");
            Px::set([Px::attribute("Depth {$n}")], "Sizes {$n}");
            catalogListScreensLabel("New {$n}");
            Px::warranty();
            Fx::asSystem(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair("rail{$n}", "runner{$n}")));
            DB::table('catalog.search_log')->insert(['store_id' => Fx::storeId($n % 2 === 0 ? 'sa' : 'eg'), 'locale' => 'ar', 'query' => "word {$n}", 'results' => 0, 'searched_at' => now()]);
        }

        expect(catalogListScreensOwnQueries($browser, $uri))->toBe($recorded);
    })->with([
        'brands' => ['/admin/brands', 2],
        'categories' => ['/admin/categories', 3],
        'attributes' => ['/admin/attributes', 1],
        'one attribute' => ['/admin/attributes/{width}', 2],
        'variations' => ['/admin/variations', 3],
        'labels' => ['/admin/labels', 1],
        'warranties' => ['/admin/warranties', 1],
        'search words' => ['/admin/search-words', 4],
    ]);
});
