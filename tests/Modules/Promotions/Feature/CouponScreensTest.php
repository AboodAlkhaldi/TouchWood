<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Cp;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Promotions' coupon screens over HTTP (promotions.md §4.1 M1, M2): Marketing › Coupons, a coupon's page
| with its Details, Uses and Customers, and every change behind their buttons - what each page is given,
| who is refused, and where each refusal lands.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    // The real clock: the panel's sign-in code is checked against it.
});

/**
 * Signed in through the panel's door as a staff member holding these jobs in these stores.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function couponScreens(array $permissions, array $stores = ['sa'], RoleLevel $level = RoleLevel::Staff): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores, $level);
    $browser = new AdminBrowser('10.9.2.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

function couponScreensManager(): AdminBrowser
{
    return couponScreens([PromotionsPermissions::COUPON_MANAGE]);
}

/**
 * What a page was given.
 *
 * @return array<string, mixed>
 */
function couponScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * A field's error a redirect carries.
 *
 * @param  TestResponse<Response>  $response
 */
function couponScreensFieldError(TestResponse $response, string $field): ?string
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first($field) === '' ? null : $errors->first($field);
    }

    $message = is_array($errors) ? ($errors['default']['messages'][$field][0] ?? null) : null;

    return is_string($message) ? $message : null;
}

/**
 * The form as M2 posts it: a 15% coupon for everyone, from now, no end, unless told otherwise.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function couponScreensForm(array $with = []): array
{
    return [
        'store' => 'sa',
        'code' => 'SPRING-10',
        'name_ar' => 'قسيمة الربيع',
        'name_en' => 'Spring coupon',
        'eligibility' => 'PUBLIC',
        'discount_kind' => 'PERCENT',
        'percent' => '10',
        'cap' => '',
        'amount' => '',
        'minimum' => '',
        'starts_at' => null,
        'ends_at' => null,
        'max_uses_per_customer' => '',
        'max_uses_total' => '',
        'allow_with_points' => true,
        'on_reduced_lines' => false,
        'for_retail' => true,
        'for_wholesale' => true,
        'for_public' => true,
        'for_company' => true,
        'rules' => [],
        ...$with,
    ];
}

/** A coupon's row, as the tests read it back. */
function couponScreensRow(string $couponId): stdClass
{
    $row = DB::table('promotions.coupons')->where('id', $couponId)->first();
    assert($row instanceof stdClass);

    return $row;
}

/** A segment of the store that takes every individual who never ordered, made as the system. */
function couponScreensSegment(string $nameEn = 'Never ordered'): string
{
    return Fx::asSystem(fn (): string => app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId('sa'), 'شريحة', $nameEn, 'DYNAMIC', 'INDIVIDUAL', 'NO_ORDERS_EVER')));
}

/**
 * The page's own queries, opened a second time (warm), the panel's frame not counted (frontend.md §5).
 */
function couponScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Promotions/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

describe('M1 · the list', function () {
    it('lists the store\'s coupons, newest first, with what a reader may do and the stores offered', function () {
        Pr::coupon('OLD-5', with: ['created_at' => now()->subDays(2)]);
        Pr::coupon('NEW-20', with: ['percent' => 20, 'cap_minor' => 50_00]);
        Pr::coupon('EG-ONLY', 'eg');

        $props = couponScreensProps(couponScreens([PromotionsPermissions::VIEW], ['sa', 'eg']), '/admin/coupons', 'Promotions/Admin/Coupons/Index');

        expect(array_column($props['coupons'], 'code'))->toBe(['NEW-20', 'OLD-5'])
            ->and([$props['coupons'][0]['percent'], $props['coupons'][0]['cap'], $props['coupons'][0]['state'], $props['coupons'][0]['usesTotal']])->toBe([20, ['amount' => '50.00', 'currency' => 'SAR'], 'ACTIVE', 0])
            ->and([$props['storeCode'], $props['mayManage'], $props['nextAt'], $props['nextId']])->toBe(['sa', false, null, null])
            ->and(array_column($props['stores'], 'code'))->toBe(['sa', 'eg']);
    });

    it('filters by state and by the start of a code, and offers New Coupon to a manager', function () {
        Pr::coupon('SUMMER-15');
        Pr::coupon('SUNNY-5', with: ['active' => false]);
        Pr::coupon('WINTER-9');
        $browser = couponScreensManager();

        $byCode = couponScreensProps($browser, '/admin/coupons?code=su', 'Promotions/Admin/Coupons/Index');
        $off = couponScreensProps($browser, '/admin/coupons?state=inactive', 'Promotions/Admin/Coupons/Index');

        expect(array_column($byCode['coupons'], 'code'))->toEqualCanonicalizing(['SUMMER-15', 'SUNNY-5'])
            ->and($byCode['mayManage'])->toBeTrue()
            ->and([array_column($off['coupons'], 'code'), $off['state']])->toBe([['SUNNY-5'], 'INACTIVE']);
    });

    it('refuses a reader with no Marketing job, and a store not offered', function () {
        couponScreens(['access.customer.view'])->get('/admin/coupons')->assertForbidden();
        couponScreens([PromotionsPermissions::VIEW])->get('/admin/coupons?store=eg')->assertForbidden();
    });

    it('is on the menu under Marketing for anyone with one of its jobs', function () {
        $menu = couponScreensProps(couponScreens([PromotionsPermissions::GIFT_MANAGE]), '/admin', 'Admin/Home')['menu'];
        $marketing = array_values(array_filter($menu, static fn (array $group): bool => $group['key'] === 'marketing'));

        expect(array_column($marketing[0]['entries'] ?? [], 'key'))->toContain('coupons');
    });
});

describe('M2 · a new coupon', function () {
    it('gives the form the store\'s ceiling, currency, and the picker\'s categories and brands by name', function () {
        Pr::ceiling(25);
        $category = Cp::category('Kitchens');
        Cp::brand('Blum');

        $props = couponScreensProps(couponScreensManager(), '/admin/coupons/new?store=sa', 'Promotions/Admin/Coupons/Show');

        expect([$props['coupon'], $props['tab'], $props['ceilingPercent'], $props['currency'], $props['exponent'], $props['mayManage']])->toBe([null, 'details', 25, 'SAR', 2, true])
            ->and((string) (array_column($props['categories'], 'name', 'id')[$category] ?? ''))->toStartWith('Kitchens')
            ->and(array_column($props['brands'], 'name'))->toHaveCount(1)
            ->and($props['products'])->toBe([]);
    });

    it('creates the coupon in the store the form names and opens its page', function () {
        $response = couponScreensManager()->post('/admin/coupons', couponScreensForm(['cap' => '50.00', 'max_uses_total' => '٣٠']));

        $id = (string) DB::table('promotions.coupons')->where('code', 'SPRING-10')->value('id');
        $response->assertRedirect("/admin/coupons/{$id}");
        $row = couponScreensRow($id);

        expect([$row->store_id, $row->percent, $row->cap_minor, $row->max_uses_total, AdminBrowser::flashed($response, 'status')])
            ->toBe([Fx::storeId('sa'), 10, 50_00, 30, 'Coupon created.']);
    });

    it('says an amount it cannot read beside its own box, and a refused rule beside the use case\'s field', function () {
        $browser = couponScreensManager();

        $badCap = $browser->post('/admin/coupons', couponScreensForm(['cap' => '1.234']));
        $badPercent = $browser->post('/admin/coupons', couponScreensForm(['percent' => '120']));

        expect(couponScreensFieldError($badCap, 'cap'))->toBe('Enter an amount with at most 2 decimals.')
            ->and(couponScreensFieldError($badPercent, 'percent'))->not->toBeNull()
            ->and(DB::table('promotions.coupons')->count())->toBe(0);
    });

    it('refuses a reader who may only view, and a store not offered', function () {
        // A change refused goes back to the form with the refusal, as every panel form's does (FormErrors).
        $viewer = couponScreens([PromotionsPermissions::VIEW])->post('/admin/coupons', couponScreensForm());
        $otherStore = couponScreensManager()->post('/admin/coupons', couponScreensForm(['store' => 'eg']));

        expect([$viewer->getStatusCode(), $otherStore->getStatusCode()])->toBe([302, 302])
            ->and(couponScreensFieldError($viewer, 'form'))->toBe("Couldn't do that: it isn't one of your jobs. Ask an administrator for it.")
            ->and(DB::table('promotions.coupons')->count())->toBe(0);
    });
});

describe('M2 · a coupon', function () {
    it('gives the form the coupon as its boxes hold it, rules named with a category\'s path, read only for a viewer', function () {
        $parent = Cp::category('Doors');
        $category = Cp::category('Hinges', $parent);
        // The store's first brand is its default, which stays on; the second may be switched off.
        Cp::brand('Blum');
        $off = Cp::brand('Old');
        DB::table('catalog.brands')->where('id', $off)->update(['is_active' => false]);
        $coupon = Pr::coupon('DOORS-15', with: ['cap_minor' => 100_00, 'minimum_minor' => 250_50], rules: [['INCLUDE', 'category', $category], ['EXCLUDE', 'brand', $off]]);

        $props = couponScreensProps(couponScreens([PromotionsPermissions::VIEW]), "/admin/coupons/{$coupon}", 'Promotions/Admin/Coupons/Show');
        $form = $props['coupon'];

        expect([$form['code'], $form['percent'], $form['cap'], $form['minimum'], $form['endsAt'], $props['mayManage']])->toBe(['DOORS-15', 15, '100.00', '250.50', null, false])
            ->and([$form['rules'][0]['kind'], $form['rules'][0]['target'], $form['rules'][0]['id']])->toBe(['INCLUDE', 'category', $category])
            ->and((string) $form['rules'][0]['path'])->toMatch('/^Doors \d+ › Hinges \d+$/')
            ->and([$form['rules'][0]['sold'], $form['rules'][1]['target'], $form['rules'][1]['sold'], $form['rules'][1]['path']])->toBe([true, 'brand', false, null]);
    });

    it('saves a change, turns it off and on, and says so', function () {
        $coupon = Pr::coupon('SUMMER-15');
        $browser = couponScreensManager();

        $saved = $browser->post("/admin/coupons/{$coupon}", couponScreensForm(['discount_kind' => 'FIXED', 'percent' => '', 'amount' => '25']));
        $off = $browser->post("/admin/coupons/{$coupon}/switch", ['active' => false]);

        expect([AdminBrowser::flashed($saved, 'status'), couponScreensRow($coupon)->discount_kind, couponScreensRow($coupon)->amount_minor])->toBe(['Coupon saved.', 'FIXED', 25_00])
            ->and([AdminBrowser::flashed($off, 'status'), couponScreensRow($coupon)->active])->toBe(['Coupon turned off.', false]);

        $browser->post("/admin/coupons/{$coupon}/switch", ['active' => true]);
        expect(couponScreensRow($coupon)->active)->toBeTrue();
    });

    it('deletes a coupon never used, back to the list, and refuses one used', function () {
        $unused = Pr::coupon('UNUSED-1');
        $used = Pr::coupon('USED-1');
        Pr::used($used, Fx::customer('used@example.test'));
        $browser = couponScreensManager();

        $browser->post("/admin/coupons/{$unused}/delete")->assertRedirect('/admin/coupons');
        $browser->post("/admin/coupons/{$used}/delete")->assertRedirect();

        expect(DB::table('promotions.coupons')->pluck('code')->all())->toBe(['USED-1']);
    });

    it('lists the uses with the customer\'s name, newest first', function () {
        $coupon = Pr::coupon('SUMMER-15');
        $customer = Fx::customer('nora@example.test');
        DB::table('access.customers')->where('id', $customer)->update(['first_name' => 'Nora', 'last_name' => 'Screen']);
        Pr::used($coupon, $customer, discountMinor: 12_50);

        $props = couponScreensProps(couponScreens([PromotionsPermissions::VIEW]), "/admin/coupons/{$coupon}?tab=uses", 'Promotions/Admin/Coupons/Show');

        expect([$props['tab'], $props['uses'][0]['customer']['name'], $props['uses'][0]['discount'], $props['customers']])
            ->toBe(['uses', 'Nora Screen', ['amount' => '12.50', 'currency' => 'SAR'], []]);
    });

    it('gives an assigned coupon to a segment\'s members, lists them, and removes one unused', function () {
        $coupon = Pr::coupon('VIP-20', with: ['eligibility' => 'ASSIGNED']);
        $customer = Fx::customer('lina@example.test');
        $segment = couponScreensSegment();
        $browser = couponScreens([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE]);

        $given = $browser->post("/admin/coupons/{$coupon}/assign", ['segment' => $segment]);
        $props = couponScreensProps($browser, "/admin/coupons/{$coupon}?tab=customers", 'Promotions/Admin/Coupons/Show');

        expect(AdminBrowser::flashed($given, 'status'))->toBe('Coupon given to the segment\'s members.')
            ->and([$props['tab'], array_column(array_column($props['customers'], 'customer'), 'id'), $props['customers'][0]['segment'] ?? null])->toBe(['customers', [$customer], 'Never ordered'])
            ->and(array_column($props['segments'], 'id'))->toBe([$segment]);

        $browser->post("/admin/coupons/{$coupon}/assignments/{$customer}/remove");
        expect(DB::table('promotions.coupon_assignments')->where('coupon_id', $coupon)->count())->toBe(0);
    });

    it('changes nothing of another store\'s coupon, whatever the button', function () {
        $egCoupon = Pr::coupon('EG-ONLY', 'eg', with: ['eligibility' => 'ASSIGNED']);
        $before = (array) couponScreensRow($egCoupon);
        $browser = couponScreens([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE]);

        $answers = [
            $browser->post("/admin/coupons/{$egCoupon}", couponScreensForm(['percent' => '50']))->getStatusCode(),
            $browser->post("/admin/coupons/{$egCoupon}/switch", ['active' => false])->getStatusCode(),
            $browser->post("/admin/coupons/{$egCoupon}/assign", ['segment' => couponScreensSegment()])->getStatusCode(),
            $browser->post("/admin/coupons/{$egCoupon}/delete")->getStatusCode(),
        ];

        expect($answers)->each->not->toBe(500)
            ->and((array) couponScreensRow($egCoupon))->toBe($before)
            ->and(DB::table('promotions.coupon_assignments')->where('coupon_id', $egCoupon)->count())->toBe(0);
    });

    it('keeps the saved start to the second when the form sends it back unchanged', function () {
        $coupon = Pr::coupon('SECONDS-1', with: ['starts_at' => '2026-10-01 08:00:27+00']);
        $props = couponScreensProps(couponScreensManager(), "/admin/coupons/{$coupon}", 'Promotions/Admin/Coupons/Show');

        couponScreensManager()->post("/admin/coupons/{$coupon}", couponScreensForm(['starts_at' => $props['coupon']['startsAt'], 'name_en' => 'Renamed']));

        expect([couponScreensRow($coupon)->name_en, CarbonImmutable::parse((string) couponScreensRow($coupon)->starts_at)->utc()->format('Y-m-d H:i:s')])
            ->toBe(['Renamed', '2026-10-01 08:00:27']);
    });

    it('refuses a date that does not exist rather than reading it as another', function () {
        $coupon = Pr::coupon('ROLL-1');

        $answer = couponScreensManager()->post("/admin/coupons/{$coupon}", couponScreensForm(['ends_at' => '2027-02-30T10:00:00+03:00']));

        expect(couponScreensFieldError($answer, 'ends_at'))->toBe('Enter a date and a time.')
            ->and(couponScreensRow($coupon)->ends_at)->toBeNull();
    });

    it('opens Details for a public coupon asked for its Customers tab', function () {
        $coupon = Pr::coupon('SUMMER-15');

        expect(couponScreensProps(couponScreens([PromotionsPermissions::VIEW]), "/admin/coupons/{$coupon}?tab=customers", 'Promotions/Admin/Coupons/Show')['tab'])->toBe('details');
    });

    it('answers another store\'s coupon, and an unknown one, as not found or refused, never a 500', function () {
        $egCoupon = Pr::coupon('EG-ONLY', 'eg');
        $browser = couponScreens([PromotionsPermissions::VIEW]);

        expect($browser->get("/admin/coupons/{$egCoupon}")->getStatusCode())->toBeIn([403, 404])
            ->and($browser->get('/admin/coupons/01k0000000000000000000000z')->getStatusCode())->toBe(404);
    });

    it('reads each coupon page in a fixed few queries, the same for one row as for six', function () {
        $first = Pr::coupon('ROW-0');
        Pr::used($first, Fx::customer('use0@example.test'));
        $pages = ['/admin/coupons', "/admin/coupons/{$first}", "/admin/coupons/{$first}?tab=uses"];
        // Each count from a reader signed in after the rows are there: rows made in the middle of a
        // session would make it someone else's (lesson 214).
        $counts = static function () use ($pages): array {
            $browser = couponScreensManager();

            return array_map(static fn (string $uri): int => couponScreensOwnQueries($browser, $uri), $pages);
        };
        $one = $counts();

        foreach (range(1, 5) as $n) {
            Pr::used(Pr::coupon("ROW-{$n}"), Fx::customer("row{$n}@example.test"));
            Pr::used($first, Fx::customer("use{$n}@example.test"));
        }

        expect($counts())->toBe($one)
            ->and($one)->toBe([3, 8, 12]);
    });
});
