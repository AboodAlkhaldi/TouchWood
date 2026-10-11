<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Access\Application\Customer\CustomerShopperType;
use Modules\Platform\Application\Routing\GuestShoppers;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Who is looking at the shop (catalog.md §1.13, §2.4, amendment 14(a); access.md amendment 67):
| Platform's ShopperType, which Access binds from the signed-in customer's account type — an
| individual, a company whatever its company's state, else a guest: nobody signed in, a staff member
| viewing the shop, the system.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    // A shop page that says who is looking, behind the shop's own middleware, as every shop page is.
    Route::prefix('{store}/{locale}')->middleware(StorefrontArea::MIDDLEWARE)
        ->get('shopper-type-probe', static fn (ShopperType $shoppers): string => $shoppers->current()->value);
});

function shopperTypeSignedIn(string $email, string $accountType): AdminBrowser
{
    Fx::customer($email, 'sa', $accountType);
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

it('is Access\'s; Platform\'s own answer, until it is bound, is a guest', function () {
    expect(app(ShopperType::class))->toBeInstanceOf(CustomerShopperType::class)
        ->and((new GuestShoppers)->current())->toBe(ShopperKind::Guest);
});

it('answers a guest for nobody signed in, an individual and a company by their account type', function () {
    (new AdminBrowser)->get('/sa/en/shopper-type-probe')->assertOk()->assertSeeText('GUEST');
    shopperTypeSignedIn('sara@example.test', 'individual')->get('/sa/en/shopper-type-probe')->assertOk()->assertSeeText('INDIVIDUAL');
    // A company account that never applied for a company here: the company side all the same.
    shopperTypeSignedIn('works@example.test', 'company')->get('/sa/en/shopper-type-probe')->assertOk()->assertSeeText('COMPANY');
});

it('answers a guest while a staff view holds, though a customer is signed in underneath, and the customer again once it ends', function () {
    $browser = shopperTypeSignedIn('sara@example.test', 'company');
    $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    $browser->post('/admin/staff-view', ['store' => 'sa'])->assertRedirect('/sa/en');
    $browser->get('/sa/en/shopper-type-probe')->assertOk()->assertSeeText('GUEST');

    $browser->post('/staff-view/leave', ['store' => 'sa'])->assertRedirect('/sa/en');
    $browser->get('/sa/en/shopper-type-probe')->assertOk()->assertSeeText('COMPANY');
});

it('answers a guest for a staff member and for the system', function () {
    Fx::actAsStaff(Fx::staff());
    expect(app(ShopperType::class)->current())->toBe(ShopperKind::Guest);

    Fx::actAs(Actor::system());
    expect(app(ShopperType::class)->current())->toBe(ShopperKind::Guest);
});

it('reads a customer\'s account once however often it is asked, and nothing for a guest', function () {
    $customerId = Fx::customer('sara@example.test', 'sa', 'individual');
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    /** @var ArrayObject<int, string> $queries */
    $queries = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries[] = $query->sql;
    });

    expect(app(ShopperType::class)->current())->toBe(ShopperKind::Guest)
        ->and(count($queries))->toBe(0);

    Fx::actAsCustomer($customerId);
    $shoppers = app(ShopperType::class);

    expect($shoppers->current())->toBe(ShopperKind::Individual)
        ->and($shoppers->current())->toBe(ShopperKind::Individual)
        ->and(count($queries))->toBe(1);
});
