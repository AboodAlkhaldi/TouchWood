<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Sales\Application\SalesPermissions;
use Modules\Shipping\Application\ShippingPermissions as P;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The carriers screens (shipping.md §4.1 part 1; the owner, 2026-10-29, (a) in all sixteen): C1 the list by
| country, C2 the dialog's posts, C3 a carrier's page, the menu entry. Step 3's use cases have their own
| tests (Integration/CarriersTest); these are about the screens. Every helper is named after this file's
| subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

// Measured on 2026-10-29, warm, as a Super Admin: 38 for the list and 37 for a carrier's page. The page's own
// reads are three (the carriers, the store's countries, the address countries) - plus the logos' one read when
// any has a logo; the rest is the panel's frame (the session, the menu and its counts), the same on every page.
// Plus one from stage 7 step 4 (2026-10-30): the Carriers entry's count of places no carrier serves reads its
// kept count, one query (shipping.md §1.6). Then part 2 of the screens (2026-10-30): the list reads the places
// no carrier serves and the store-wide free-shipping amount (43); a carrier's page its prices and that amount
// (41 with no price; PricesScreensTest pins it with prices).
const CARRIERS_SCREEN_LIST_QUERIES = 43;
const CARRIERS_SCREEN_PAGE_QUERIES = 41;

beforeEach(function () {
    // The sweep of old sessions runs on 2 requests in 100 (config/session.php) and adds a DELETE: off, so
    // a page's count never depends on a dice roll (AdminFrameQueryBudgetTest).
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

/** Signed in through the panel's door. */
function carriersScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.2.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

function carriersScreenSuper(): AdminBrowser
{
    return carriersScreenSignIn(Fx::staff(superAdmin: true));
}

/**
 * A carrier added through the dialog's post; its id.
 *
 * @param  array<string, mixed>  $fields
 */
function carriersScreenAdd(AdminBrowser $browser, string $nameEn, array $fields = []): string
{
    $browser->post('/admin/shipping/carriers', [
        'store' => 'sa', 'country' => 'SA', 'name_ar' => 'ناقل '.$nameEn, 'name_en' => $nameEn, ...$fields,
    ])->assertRedirect();

    return (string) (DB::table('shipping.carriers')->where('name_en', $nameEn)->value('id') ?? throw new RuntimeException("No carrier \"{$nameEn}\"."));
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function carriersScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * The menu entry keys the panel offers on a page, as "group/key".
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function carriersScreenMenu(TestResponse $response): array
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
 * Every query of a page opened the second time (warm: the reader's permissions and the stores cached).
 * One clock for both reads: the staff pass writes its last-seen time once a minute, and a minute turning
 * between them would count that write against the page.
 */
function carriersScreenQueries(AdminBrowser $browser, string $uri): int
{
    CarbonImmutable::setTestNow(CarbonImmutable::now());

    try {
        $browser->get($uri)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
    } finally {
        CarbonImmutable::setTestNow();
    }

    return $count;
}

describe('the menu and who may open the screens', function () {
    it('offers Carriers in the Shipping group to whoever may view them, and to no one else', function () {
        expect(carriersScreenMenu(carriersScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']))->get('/admin')))->toContain('shipping/carriers');

        $admin = carriersScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW, P::CARRIERS_MANAGE], ['sa'], RoleLevel::Admin));
        expect(carriersScreenMenu($admin->get('/admin')))->toContain('shipping/carriers');
        $admin->get('/admin/shipping/carriers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('mayManage', true));

        // Managing alone does not open them: the reads take viewing, a plain role permission (§9.4 #1), so
        // the entry is not offered rather than leading to the refusal page.
        $manager = carriersScreenSignIn(Fx::staffWith([P::CARRIERS_MANAGE], ['sa'], RoleLevel::Admin));
        expect(carriersScreenMenu($manager->get('/admin')))->not->toContain('shipping/carriers');

        // Someone with a job elsewhere in the panel, none in Shipping.
        $nobody = carriersScreenSignIn(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(carriersScreenMenu($nobody->get('/admin')))->not->toContain('shipping/carriers');
        $nobody->get('/admin/shipping/carriers')->assertForbidden();
    });

    it('opens a store the reader may use, and refuses one they may not', function () {
        $viewer = carriersScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']));

        $viewer->get('/admin/shipping/carriers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'sa'));
        $viewer->get('/admin/shipping/carriers?store=eg')->assertForbidden();
        $viewer->get('/admin/shipping/carriers?store=zz')->assertForbidden();
    });
});

describe('C1 · the carriers by country', function () {
    it('lists a section per ship-to country, the own first, its carriers in their order with their details', function () {
        $browser = carriersScreenSuper();
        $aramex = carriersScreenAdd($browser, 'Aramex', ['phone' => '0096650 123 4567', 'website' => 'https://aramex.com', 'tracking_pattern' => 'https://aramex.com/track?n={number}']);
        $smsa = carriersScreenAdd($browser, 'SMSA', ['active' => false]);

        $browser->get('/admin/shipping/carriers?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Shipping/Admin/Carriers/Index')
            ->where('mayManage', true)
            ->where('countries.0.code', 'SA')->where('countries.0.own', true)->where('countries.0.name', 'Saudi Arabia')
            ->has('countries.0.carriers', 2)
            ->where('countries.0.carriers.0.id', $aramex)
            ->where('countries.0.carriers.0.nameAr', 'ناقل Aramex')
            ->where('countries.0.carriers.0.phone', '+966501234567')
            ->where('countries.0.carriers.0.website', 'https://aramex.com')
            ->where('countries.0.carriers.0.trackingPattern', 'https://aramex.com/track?n={number}')
            ->where('countries.0.carriers.0.logo', null)
            ->where('countries.0.carriers.0.active', true)
            ->where('countries.0.carriers.1.id', $smsa)
            ->where('countries.0.carriers.1.active', false)
            ->where('stores', fn (Collection $stores): bool => $stores->pluck('code')->contains('eg')));
    });

    it('keeps a country with no carrier, empty (Q11)', function () {
        carriersScreenSuper()->get('/admin/shipping/carriers?store=eg')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('countries.0.code', 'EG')->has('countries.0.carriers', 0));
    });

    it('is read-only for whoever may only view (Q12)', function () {
        carriersScreenAdd(carriersScreenSuper(), 'Aramex');
        $viewer = carriersScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']));

        $viewer->get('/admin/shipping/carriers')->assertInertia(fn (AssertableInertia $page) => $page->where('mayManage', false)->has('countries.0.carriers', 1));
        $refused = $viewer->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'SA', 'name_ar' => 'سمسا', 'name_en' => 'SMSA']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(DB::table('shipping.carriers')->where('name_en', 'SMSA')->exists())->toBeFalse();
    });

    it('refuses every change of whoever may only view, the handlers asking again', function () {
        $id = carriersScreenAdd(carriersScreenSuper(), 'Aramex');
        $viewer = carriersScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']));

        foreach ([
            "/admin/shipping/carriers/{$id}" => ['store' => 'sa', 'name_ar' => 'غير', 'name_en' => 'Changed'],
            "/admin/shipping/carriers/{$id}/switch" => ['store' => 'sa', 'active' => false],
            "/admin/shipping/carriers/{$id}/delete" => ['store' => 'sa'],
            '/admin/shipping/carriers/order?store=sa&country=SA' => ['ids' => [$id]],
        ] as $uri => $form) {
            expect(AdminBrowser::formError($viewer->post($uri, $form)))->not->toBeNull();
        }

        expect((array) DB::table('shipping.carriers')->where('id', $id)->first(['name_en', 'active']))->toBe(['name_en' => 'Aramex', 'active' => true]);
    });

    it('reads the page in a bounded number of queries', function () {
        $browser = carriersScreenSuper();
        carriersScreenAdd($browser, 'Aramex');
        carriersScreenAdd($browser, 'SMSA');
        $one = carriersScreenQueries($browser, '/admin/shipping/carriers?store=sa');
        carriersScreenAdd($browser, 'Naqel');
        carriersScreenAdd($browser, 'DHL');

        // Not one more read per carrier.
        expect(carriersScreenQueries($browser, '/admin/shipping/carriers?store=sa'))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(CARRIERS_SCREEN_LIST_QUERIES);
    });
});

describe('C2 · adding and changing a carrier', function () {
    it('adds one in the section\'s country, with its toast', function () {
        $browser = carriersScreenSuper();
        $response = $browser->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'sa', 'name_ar' => 'أرامكس', 'name_en' => 'Aramex']);

        expect(AdminBrowser::flashed($response, 'status'))->toBe('Carrier added.')
            ->and(DB::table('shipping.carriers')->where('name_en', 'Aramex')->value('country_code'))->toBe('SA');
    });

    it('says a refusal beside its box: a name taken in its language, a website that is not https', function () {
        $browser = carriersScreenSuper();
        carriersScreenAdd($browser, 'Aramex');

        $taken = carriersScreenErrors($browser->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'SA', 'name_ar' => 'غير', 'name_en' => ' aramex ']));
        $web = carriersScreenErrors($browser->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'SA', 'name_ar' => 'سمسا', 'name_en' => 'SMSA', 'website' => 'http://smsa.com']));

        expect($taken)->toHaveKey('name_en')
            ->and($web)->toHaveKey('website')
            ->and(DB::table('shipping.carriers')->count())->toBe(1);
    });

    it('refuses a country the store does not ship to, at the top of the dialog', function () {
        $refused = carriersScreenSuper()->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'EG', 'name_ar' => 'سمسا', 'name_en' => 'SMSA']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(DB::table('shipping.carriers')->count())->toBe(0);
    });

    it('takes a logo uploaded with the dialog, and removes it', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex', ['logo' => UploadedFile::fake()->image('aramex.png', 200, 200)]);

        $logo = DB::table('shipping.carriers')->where('id', $id)->value('logo_media_id');
        expect($logo)->not->toBeNull();

        $browser->post("/admin/shipping/carriers/{$id}", ['store' => 'sa', 'name_ar' => 'ناقل Aramex', 'name_en' => 'Aramex', 'logo_media_id' => $logo])->assertRedirect();
        expect(DB::table('shipping.carriers')->where('id', $id)->value('logo_media_id'))->toBe($logo);

        $browser->post("/admin/shipping/carriers/{$id}", ['store' => 'sa', 'name_ar' => 'ناقل Aramex', 'name_en' => 'Aramex', 'logo_media_id' => $logo, 'remove_logo' => true])->assertRedirect();
        expect(DB::table('shipping.carriers')->where('id', $id)->value('logo_media_id'))->toBeNull();
    });

    it('refuses a post that names no store, rather than taking the first one', function () {
        $refused = carriersScreenSuper()->post('/admin/shipping/carriers', ['country' => 'SA', 'name_ar' => 'سمسا', 'name_en' => 'SMSA']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(DB::table('shipping.carriers')->count())->toBe(0);
    });

    it('finds no carrier of another store: changing, switching and deleting it there are refused', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex');

        foreach ([
            "/admin/shipping/carriers/{$id}" => ['store' => 'eg', 'name_ar' => 'غير', 'name_en' => 'Changed'],
            "/admin/shipping/carriers/{$id}/switch" => ['store' => 'eg', 'active' => false],
            "/admin/shipping/carriers/{$id}/delete" => ['store' => 'eg'],
        ] as $uri => $form) {
            expect(AdminBrowser::formError($browser->post($uri, $form)))->not->toBeNull();
        }

        expect((array) DB::table('shipping.carriers')->where('id', $id)->first(['name_en', 'active']))->toBe(['name_en' => 'Aramex', 'active' => true]);
    });

    it('changes the details, the country staying', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex');

        $response = $browser->post("/admin/shipping/carriers/{$id}", ['store' => 'sa', 'country' => 'EG', 'name_ar' => 'أرامكس', 'name_en' => 'Aramex Express', 'tracking_pattern' => 'https://aramex.com/t/{number}']);

        expect(AdminBrowser::flashed($response, 'status'))->toBe('Carrier saved.')
            ->and((array) DB::table('shipping.carriers')->where('id', $id)->first(['name_en', 'country_code', 'tracking_pattern']))
            ->toBe(['name_en' => 'Aramex Express', 'country_code' => 'SA', 'tracking_pattern' => 'https://aramex.com/t/{number}']);
    });
});

describe('the switch, the order and Delete', function () {
    it('switches a carrier off and on, each with its toast', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex');

        expect(AdminBrowser::flashed($browser->post("/admin/shipping/carriers/{$id}/switch", ['store' => 'sa', 'active' => false]), 'status'))->toBe('Carrier switched off.')
            ->and(DB::table('shipping.carriers')->where('id', $id)->value('active'))->toBeFalse()
            ->and(AdminBrowser::flashed($browser->post("/admin/shipping/carriers/{$id}/switch", ['store' => 'sa', 'active' => true]), 'status'))->toBe('Carrier switched on.');
    });

    it('saves a country\'s order, dragged or moved to the top (Q7)', function () {
        $browser = carriersScreenSuper();
        [$a, $b, $c] = [carriersScreenAdd($browser, 'Aramex'), carriersScreenAdd($browser, 'SMSA'), carriersScreenAdd($browser, 'Naqel')];
        $order = fn (): array => DB::table('shipping.carriers')->orderBy('position')->pluck('id')->all();

        $browser->post('/admin/shipping/carriers/order?store=sa&country=SA', ['ids' => [$c, $a, $b]])->assertRedirect();
        expect($order())->toBe([$c, $a, $b]);

        // Move to Top sends the one id: the others follow in their order (§9.4 #5).
        $browser->post('/admin/shipping/carriers/order?store=sa&country=SA', ['ids' => [$b]])->assertRedirect();
        expect($order())->toBe([$b, $c, $a]);
    });

    it('refuses deleting a carrier that is on, and deletes one that is off - back to the list from its page', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex');

        expect(AdminBrowser::formError($browser->post("/admin/shipping/carriers/{$id}/delete", ['store' => 'sa'])))->toContain('off');

        $browser->post("/admin/shipping/carriers/{$id}/switch", ['store' => 'sa', 'active' => false]);
        $browser->post("/admin/shipping/carriers/{$id}/delete", ['store' => 'sa', 'from_page' => true])->assertRedirect('/admin/shipping/carriers?store=sa');

        expect(DB::table('shipping.carriers')->where('id', $id)->exists())->toBeFalse();
    });
});

describe('C3 · a carrier\'s page', function () {
    it('shows the carrier in its store, with its country\'s name', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex', ['website' => 'https://aramex.com']);

        $browser->get("/admin/shipping/carriers/{$id}?store=sa")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Shipping/Admin/Carriers/Show')
            ->where('carrier.id', $id)->where('carrier.website', 'https://aramex.com')
            ->where('countryName', 'Saudi Arabia')->where('mayManage', true)->where('storeCode', 'sa'));

        expect(carriersScreenQueries($browser, "/admin/shipping/carriers/{$id}?store=sa"))->toBeLessThanOrEqual(CARRIERS_SCREEN_PAGE_QUERIES);
    });

    it('is not found in another store, or when there is none', function () {
        $browser = carriersScreenSuper();
        $id = carriersScreenAdd($browser, 'Aramex');

        $browser->get("/admin/shipping/carriers/{$id}?store=eg")->assertNotFound();
        $browser->get('/admin/shipping/carriers/01JZZZZZZZZZZZZZZZZZZZZZZZ?store=sa')->assertNotFound();
    });
});

describe('the ship-to refusal on the Stores page (Q14)', function () {
    it('names the country whose carriers hold it, beside the refusal, and nothing for another refusal', function () {
        $browser = carriersScreenSuper();
        // Bahrain on the Saudi store's list, through the panel: a place list first (Platform's rule).
        $browser->post('/admin/places', ['country' => 'BH'])->assertRedirect();
        $browser->post('/admin/places/bh/places', ['kind' => 'REGION', 'parent' => '', 'name_ar' => 'العاصمة', 'name_en' => 'Capital'])->assertRedirect();
        $region = (string) DB::table('platform.places')->where('country_code', 'BH')->value('id');
        $browser->post('/admin/places/bh/places', ['kind' => 'CITY', 'parent' => $region, 'name_ar' => 'المنامة', 'name_en' => 'Manama'])->assertRedirect();
        $browser->post('/admin/stores/sa/countries', ['country' => 'BH'])->assertRedirect();
        $browser->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'BH', 'name_ar' => 'ناقل', 'name_en' => 'Bahrain Carrier', 'active' => false])->assertRedirect();
        expect(DB::table('shipping.carriers')->where('country_code', 'BH')->exists())->toBeTrue();

        $refused = carriersScreenErrors($browser->post('/admin/stores/sa/countries/bh/remove'));
        expect($refused)->toHaveKeys(['form', 'carriers'])
            ->and($refused['carriers'])->toBe(['BH']);

        // The store's own country is refused for its own reason: no link.
        $own = carriersScreenErrors($browser->post('/admin/stores/sa/countries/sa/remove'));
        expect($own)->toHaveKey('form')
            ->and(array_key_exists('carriers', $own))->toBeFalse();
    });
});
