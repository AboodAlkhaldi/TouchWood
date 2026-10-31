<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Shipping\Application\ShippingPermissions as P;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The Boxes screen (shipping.md §4.1 part 3; the owner, 2026-10-31, (a) in all fourteen): the boxes, the padding,
| the sizes packed by hand, the menu entry. Step 5's use cases have their own tests (Integration/BoxesTest);
| these are about the screen. Every helper is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

// Measured on 2026-10-31, warm, as a Super Admin: 41 with boxes and sizes packed by hand. Its own reads are the
// boxes and the padding, the hand-packed sizes (Catalog's search and its count) and their photos in one read; the
// panel's frame is most of it (CarriersScreensTest says what). It grows with neither boxes nor sizes.
const BOXES_SCREEN_QUERIES = 41;

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

function boxesScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.4.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

function boxesScreenSuper(): AdminBrowser
{
    return boxesScreenSignIn(Fx::staff(superAdmin: true));
}

/** @param  array<string, mixed>  $fields */
function boxesScreenAdd(AdminBrowser $browser, string $name, array $fields = []): string
{
    $browser->post('/admin/shipping/boxes', ['store' => 'sa', 'name' => $name, 'length_mm' => '400', 'width_mm' => '300', 'height_mm' => '200', 'max_weight_g' => '15000', ...$fields])->assertRedirect();

    return (string) (DB::table('shipping.boxes')->where('name', $name)->value('id') ?? throw new RuntimeException("No box \"{$name}\"."));
}

/** A size the Saudi store sells (as step 5's own tests make one); its id. */
function boxesScreenSize(string $code, string $product = 'Chair'): string
{
    $store = (string) DB::table('platform.stores')->where('code', 'sa')->value('id');
    $productId = Px::product("{$product} {$code}");
    $variant = Px::variant($productId, $code);
    DB::table('catalog.store_products')->insert(['store_id' => $store, 'product_id' => $productId, 'not_available_now' => false, 'retail_minimum' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('catalog.store_variants')->insert([
        'store_id' => $store, 'product_id' => $productId, 'variant_id' => $variant, 'is_active' => true, 'not_available_now' => false,
        'sells_retail' => true, 'sells_wholesale' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $variant;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function boxesScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function boxesScreenMenu(TestResponse $response): array
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

function boxesScreenQueries(AdminBrowser $browser, string $uri): int
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

describe('the menu and who may open it (B1)', function () {
    it('offers Boxes to whoever may manage boxes, and to no one else', function () {
        $manager = boxesScreenSignIn(Fx::staffWith([P::BOXES_MANAGE], ['sa']));
        expect(boxesScreenMenu($manager->get('/admin')))->toContain('shipping/boxes');
        $manager->get('/admin/shipping/boxes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Shipping/Admin/Boxes/Index')->where('storeCode', 'sa'));

        $viewer = boxesScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']));
        expect(boxesScreenMenu($viewer->get('/admin')))->not->toContain('shipping/boxes');
        $viewer->get('/admin/shipping/boxes')->assertForbidden();
    });

    it('saves the padding as a boxes manager does, through the settings route (B10)', function () {
        $manager = boxesScreenSignIn(Fx::staffWith([P::BOXES_MANAGE], ['sa']));

        $manager->post('/admin/settings/shipping.boxes.padding_mm', ['store' => 'sa', 'value' => '15'])->assertRedirect();
        $manager->get('/admin/shipping/boxes?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('paddingMm', 15));

        expect(AdminBrowser::formError($manager->post('/admin/settings/shipping.boxes.padding_mm', ['store' => 'sa', 'value' => '101'])))->not->toBeNull();
    });

    it('leaves the padding off the Settings page, now on Boxes (B10)', function () {
        $browser = boxesScreenSuper();

        $browser->get('/admin/settings?store=sa')->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $props = (string) json_encode($page->toArray()['props']);
            expect(str_contains($props, 'shipping.boxes.padding_mm'))->toBeFalse()
                ->and(str_contains($props, 'shipping.free.above_all'))->toBeFalse();
        });
    });
});

describe('the boxes (X1, X2)', function () {
    it('lists the boxes in their order, with the padding, and the first page of sizes packed by hand', function () {
        $browser = boxesScreenSuper();
        $small = boxesScreenAdd($browser, 'Small');
        $large = boxesScreenAdd($browser, 'Large', ['length_mm' => '800', 'active' => false]);

        $browser->get('/admin/shipping/boxes?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('paddingMm', 10)
            ->has('boxes', 2)
            ->where('boxes.0.id', $small)->where('boxes.0.lengthMm', 400)->where('boxes.0.maxWeightG', 15000)->where('boxes.0.active', true)
            ->where('boxes.1.id', $large)->where('boxes.1.active', false)
            ->where('handPacked.total', 0)->where('filter', null));
    });

    it('says a refusal beside its box: a name taken, a size out of range', function () {
        $browser = boxesScreenSuper();
        boxesScreenAdd($browser, 'Small');

        expect(boxesScreenErrors($browser->post('/admin/shipping/boxes', ['store' => 'sa', 'name' => ' small ', 'length_mm' => '1', 'width_mm' => '1', 'height_mm' => '1', 'max_weight_g' => '1'])))->toHaveKey('name')
            ->and(boxesScreenErrors($browser->post('/admin/shipping/boxes', ['store' => 'sa', 'name' => 'Big', 'length_mm' => '٠', 'width_mm' => '1', 'height_mm' => '1', 'max_weight_g' => '1'])))->toHaveKey('length_mm')
            ->and(DB::table('shipping.boxes')->count())->toBe(1);
    });

    it('changes, switches, orders and deletes a box, each with its toast', function () {
        $browser = boxesScreenSuper();
        [$a, $b] = [boxesScreenAdd($browser, 'A'), boxesScreenAdd($browser, 'B')];

        expect(AdminBrowser::flashed($browser->post("/admin/shipping/boxes/{$a}", ['store' => 'sa', 'name' => 'A2', 'length_mm' => '٤٠٠', 'width_mm' => '300', 'height_mm' => '200', 'max_weight_g' => '2500']), 'status'))->toBe('Box saved.')
            ->and((array) DB::table('shipping.boxes')->where('id', $a)->first(['name', 'length_mm', 'max_weight_g']))->toBe(['name' => 'A2', 'length_mm' => 400, 'max_weight_g' => 2500])
            ->and(AdminBrowser::flashed($browser->post("/admin/shipping/boxes/{$a}/switch", ['store' => 'sa', 'active' => false]), 'status'))->toBe('Box switched off.');

        $browser->post('/admin/shipping/boxes/order?store=sa', ['ids' => [$b]])->assertRedirect();
        expect(DB::table('shipping.boxes')->orderBy('position')->pluck('id')->all())->toBe([$b, $a]);

        expect(AdminBrowser::flashed($browser->post("/admin/shipping/boxes/{$a}/delete", ['store' => 'sa']), 'status'))->toBe('Box deleted.')
            ->and(DB::table('shipping.boxes')->pluck('id')->all())->toBe([$b]);
    });

    it('refuses a post that names no store, or another store\'s box', function () {
        $browser = boxesScreenSuper();
        $box = boxesScreenAdd($browser, 'Small');

        expect(AdminBrowser::formError($browser->post('/admin/shipping/boxes', ['name' => 'X', 'length_mm' => '1', 'width_mm' => '1', 'height_mm' => '1', 'max_weight_g' => '1'])))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/admin/shipping/boxes/{$box}/delete", ['store' => 'eg'])))->not->toBeNull()
            ->and(DB::table('shipping.boxes')->count())->toBe(1);
    });

    it('reads the page in a bounded number of queries, not one more per box or size', function () {
        $browser = boxesScreenSuper();
        boxesScreenAdd($browser, 'A');
        $browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [boxesScreenSize('7001')], 'by_hand' => true])->assertRedirect();
        $one = boxesScreenQueries($browser, '/admin/shipping/boxes?store=sa');
        boxesScreenAdd($browser, 'B');
        $browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [boxesScreenSize('7002')], 'by_hand' => true])->assertRedirect();

        expect(boxesScreenQueries($browser, '/admin/shipping/boxes?store=sa'))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(BOXES_SCREEN_QUERIES);
    });
});

describe('the sizes packed by hand (X3, B11, B12)', function () {
    it('finds the store\'s sizes, packs several by hand at once, lists them, and packs one automatically again', function () {
        $browser = boxesScreenSuper();
        [$chair, $table] = [boxesScreenSize('8001', 'Chair'), boxesScreenSize('8002', 'Table')];

        $found = $browser->get('/admin/shipping/boxes/sizes?store=sa&hand=0&q=80')->assertOk()->collect('rows');
        expect($found->pluck('variantId')->all())->toContain($chair, $table)
            ->and($found->firstWhere('variantId', $chair))->toHaveKeys(['productName', 'code', 'values', 'photo', 'archived']);

        expect(AdminBrowser::flashed($browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [$chair, $table], 'by_hand' => true]), 'status'))->toBe('Packed by hand from now on.');
        $browser->get('/admin/shipping/boxes?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('handPacked.total', 2));
        // A hand-packed size leaves the add search.
        expect($browser->get('/admin/shipping/boxes/sizes?store=sa&hand=0&q=80')->collect('rows')->pluck('variantId')->all())->not->toContain($chair);

        $browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [$chair], 'by_hand' => false])->assertRedirect();
        $browser->get('/admin/shipping/boxes?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('handPacked.total', 1)->where('handPacked.rows.0.variantId', $table));
    });

    it('filters the list by what is typed', function () {
        $browser = boxesScreenSuper();
        $browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [boxesScreenSize('9001', 'Chair'), boxesScreenSize('9002', 'Table')], 'by_hand' => true])->assertRedirect();

        $browser->get('/admin/shipping/boxes?store=sa&filter=Table')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', 'Table')->where('handPacked.total', 1));
    });

    it('pages the list packed by hand with its cursor, and filters it (B12)', function () {
        $browser = boxesScreenSuper();
        // One more than Catalog's page of 50.
        $sizes = array_map(static fn (int $n): string => boxesScreenSize((string) (9200 + $n), 'Lamp'), range(1, 51));
        $browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => $sizes, 'by_hand' => true])->assertRedirect();

        $next = null;
        $browser->get('/admin/shipping/boxes?store=sa')->assertInertia(function (AssertableInertia $page) use (&$next): void {
            $props = $page->toArray()['props']['handPacked'];
            expect($props['total'])->toBe(51)->and(count($props['rows']))->toBe(50)->and($props['next'])->not->toBeNull();
            $next = $props['next'];
        });

        $more = $browser->get('/admin/shipping/boxes/sizes?store=sa&hand=1&after='.urlencode((string) $next))->assertOk()->json();
        expect(count($more['rows']))->toBe(1)
            ->and($browser->get('/admin/shipping/boxes/sizes?store=sa&hand=1&q=9251')->assertOk()->json('total'))->toBe(1);
    });

    it('packs the sizes it can and names the ones refused - archived meanwhile (X3)', function () {
        $browser = boxesScreenSuper();
        [$chair, $table] = [boxesScreenSize('9301', 'Chair'), boxesScreenSize('9302', 'Table')];
        DB::table('catalog.variants')->where('id', $table)->update(['is_archived' => true]);

        $refused = boxesScreenErrors($browser->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [$chair, $table], 'by_hand' => true]));

        expect($refused)->toHaveKeys(['form', 'failed'])->and($refused['failed'])->toBe([$table]);
        $browser->get('/admin/shipping/boxes?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('handPacked.total', 1)->where('handPacked.rows.0.variantId', $chair));
    });

    it('is a manager\'s only: a staff member with another Shipping job is refused', function () {
        $size = boxesScreenSize('9101');
        $other = boxesScreenSignIn(Fx::staffWith([P::CARRIERS_MANAGE, P::CARRIERS_VIEW], ['sa'], RoleLevel::Admin));

        $other->get('/admin/shipping/boxes/sizes?store=sa&hand=0&q=91')->assertForbidden();
        expect(AdminBrowser::formError($other->post('/admin/shipping/boxes/sizes', ['store' => 'sa', 'variants' => [$size], 'by_hand' => true])))->not->toBeNull();
    });
});
