<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Sales\Application\Query\ListCarriers\ListCarriers;
use Modules\Sales\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Sales\Application\SalesPermissions as P;
use Modules\Shipping\Application\Command\CreateBox\CreateBox;
use Modules\Shipping\Application\Command\CreateBox\CreateBoxHandler;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrier;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrierHandler;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrier;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrierHandler;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroup;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroupHandler;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrier;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrierHandler;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Shared\Application\ActorContext;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The screens' side of shipping over HTTP (sales.md §4.3): what the checkout, the customer's pages, the panel's list,
| an order's page, the packing slip and the edit page are handed; and each post their dialogs send. Every helper is
| named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
});

const SHIPPING_SCREENS_ALL = [P::ORDER_VIEW, P::ORDER_APPROVE, P::ORDER_FULFIL, P::ORDER_EDIT];

/**
 * Fixtures run as someone, then the real ActorContext given back, so the requests after read their own session.
 *
 * @template T
 *
 * @param  Closure(): T  $work
 * @return T
 */
function shippingScreensAside(Closure $work): mixed
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        return $work();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/** A region of Saudi Arabia beside the test one; no carrier prices it unless a test does. */
function shippingScreensRegion(string $nameEn = 'Other Region'): string
{
    return shippingScreensAside(static fn (): string => Cx::asSuperAdmin(static fn (): string => app(AddPlaceHandler::class)->handle(new AddPlace('SA', PlaceKind::Region->value, null, $nameEn.' ع', $nameEn))));
}

/**
 * A carrier of the Saudi store pricing these places at `$fee`.
 *
 * @param  list<string>  $places
 */
function shippingScreensCarrier(string $nameEn, int $fee, array $places, ?string $pattern = null): string
{
    return shippingScreensAside(static fn (): string => Cx::asSuperAdmin(static function () use ($nameEn, $fee, $places, $pattern): string {
        $store = Fx::storeId('sa');
        $carrier = app(CreateCarrierHandler::class)->handle(new CreateCarrier($store, 'SA', 'ناقل '.$nameEn, $nameEn, trackingPattern: $pattern));
        app(SavePriceGroupHandler::class)->handle(new SavePriceGroup($store, $carrier, null, $fee, $places, PriceGroup::versionOf(app(PriceGroupRepository::class)->ofCarrier($carrier))));

        return $carrier;
    }));
}

/** @return array{customer: string, address: string, variants: list<string>, product: string} */
function shippingScreensReady(): array
{
    return shippingScreensAside(static fn (): array => Cx::ready());
}

function shippingScreensAddress(string $customer, string $region, string $country = 'SA'): string
{
    return shippingScreensAside(static function () use ($customer, $region, $country): string {
        Fx::actAsCustomer($customer);

        return app(SaveAddressHandler::class)->handle(new SaveAddress(
            storeId: Fx::storeId('sa'), label: 'Second', recipientName: 'Sara Ali',
            fields: ['administrative_area' => 'Somewhere', 'city' => 'Somewhere', 'district' => 'Middle', 'street' => 'Olaya Street', 'building' => '9'],
            countryCode: $country, regionId: $region,
        ));
    });
}

function shippingScreensPlace(string $customer, string $address): string
{
    return shippingScreensAside(static function () use ($customer, $address): string {
        Fx::actAsCustomer($customer);

        return Cx::place(Cx::start($address)->id)->id;
    });
}

function shippingScreensShopper(string $customer): AdminBrowser
{
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $email = (string) DB::table('access.customers')->where('id', $customer)->value('email');
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/** @param  list<string>  $permissions */
function shippingScreensStaff(array $permissions = SHIPPING_SCREENS_ALL): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, ['sa']);
    $browser = new AdminBrowser('10.9.9.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, mixed> */
function shippingScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];
    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** @return list<array{variantId: string, mode: string, quantity: int}> */
function shippingScreensLines(string $order): array
{
    return array_values(array_map(static fn (stdClass $line): array => ['variantId' => (string) $line->variant_id, 'mode' => (string) $line->sale_mode, 'quantity' => (int) $line->quantity],
        DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->get()->all()));
}

function shippingScreensNumber(string $order): string
{
    return (string) DB::table('sales.orders')->where('id', $order)->value('number');
}

/**
 * How many carriers a reader with these jobs gets; null: refused.
 *
 * @param  list<string>  $jobs
 */
function shippingScreensCarriersFor(array $jobs, bool $forEdit): ?int
{
    return shippingScreensAside(static function () use ($jobs, $forEdit): ?int {
        Fx::actAsStaff(Fx::staffWith($jobs, ['sa']));

        try {
            return count(app(ListCarriersHandler::class)->handle(new ListCarriers(Fx::storeId('sa'), 'SA', $forEdit)));
        } catch (Unauthorized) {
            return null;
        }
    });
}

describe('the checkout (§4.3)', function () {
    it('lists the carriers serving the address, the cheapest picked first, and takes the one asked for from the address bar', function () {
        ['customer' => $customer] = shippingScreensReady();
        $region = Cx::serve()['region'];
        $dear = shippingScreensCarrier('Dear', 4_000, [$region]);
        $browser = shippingScreensShopper($customer);

        $first = shippingScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $asked = shippingScreensProps($browser, "/sa/en/checkout?carrier={$dear}", 'Sales/Storefront/Checkout');

        expect(array_column($first['carriers'], 'name'))->toBe([Cx::CARRIER, 'Dear'])
            ->and($first['carriers'][0]['free'])->toBeFalse()
            ->and([$first['carrierId'], $first['carrierChanged'], $first['shippingPending']])->toBe([Cx::serve()['carrier'], false, false])
            ->and([$asked['carrierId'], $asked['shipping']['minor'], $asked['carrierChanged']])->toBe([$dear, 4_000, false]);
    });

    it('picks the cheapest and says so for a carrier that no longer serves the address (gap 6.9)', function () {
        ['customer' => $customer] = shippingScreensReady();
        $elsewhere = shippingScreensCarrier('Elsewhere', 100, [shippingScreensRegion()]);

        $page = shippingScreensProps(shippingScreensShopper($customer), "/sa/en/checkout?carrier={$elsewhere}", 'Sales/Storefront/Checkout');

        expect([$page['carrierId'], $page['carrierChanged']])->toBe([Cx::serve()['carrier'], true]);
    });

    it('shows a fee to be set where no carrier serves: no carriers, the total before shipping, staff contact only', function () {
        ['customer' => $customer] = shippingScreensReady();
        $address = shippingScreensAddress($customer, shippingScreensRegion());

        $page = shippingScreensProps(shippingScreensShopper($customer), "/sa/en/checkout?address={$address}", 'Sales/Storefront/Checkout');

        expect([$page['shippingPending'], $page['carriers'], $page['shipping'], $page['carrierId'], $page['ways']])->toBe([true, [], null, null, ['STAFF_CONTACT']])
            ->and($page['total']['minor'])->toBeGreaterThan(0);
    });

    it('gives no quote for an address another store serves, and a way to that store', function () {
        ['customer' => $customer] = shippingScreensReady();
        $doha = shippingScreensAside(static fn (): string => Cx::asSuperAdmin(static function (): string {
            app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));

            return app(AddPlaceHandler::class)->handle(new AddPlace('QA', PlaceKind::Region->value, null, 'الدوحة', 'Doha'));
        }));
        $qatar = shippingScreensAddress($customer, $doha, 'QA');
        shippingScreensAside(static fn () => Cx::asSuperAdmin(static fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('ae', 'QA'))));

        $page = shippingScreensProps(shippingScreensShopper($customer), "/sa/en/checkout?address={$qatar}", 'Sales/Storefront/Checkout');

        expect($page['quoteId'])->toBeNull()
            ->and($page['addressId'])->toBe($qatar)
            ->and($page['otherStore']['url'])->toEndWith('/ae/en');
    });
});

describe('the customer\'s pages (§4.3)', function () {
    it('marks an order whose fee is to be set on the order placed, My Orders and the order', function () {
        ['customer' => $customer] = shippingScreensReady();
        $order = shippingScreensPlace($customer, shippingScreensAddress($customer, shippingScreensRegion()));
        $number = shippingScreensNumber($order);
        $browser = shippingScreensShopper($customer);

        expect(shippingScreensProps($browser, "/sa/en/orders/{$number}/placed", 'Sales/Storefront/Placed')['shippingPending'])->toBeTrue()
            ->and(shippingScreensProps($browser, '/sa/en/account/orders', 'Sales/Storefront/MyOrders')['orders'][0]['shippingPending'])->toBeTrue()
            ->and(shippingScreensProps($browser, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order'))->toMatchArray(['shippingPending' => true, 'carrier' => null, 'trackingLink' => null]);
    });

    it('names the carrier ordered before it ships, and hands Track only with a link', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $number = shippingScreensNumber($order);
        $shopper = shippingScreensShopper($customer);
        $before = shippingScreensProps($shopper, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order');

        $staff = shippingScreensStaff();
        $staff->post("/admin/orders/{$order}/approve");
        $staff->post("/admin/orders/{$order}/ship-unpaid", ['allow' => true]);
        $staff->post("/admin/orders/{$order}/ship", ['other_carrier' => 'Aramex', 'tracking_number' => '77']);
        $noLink = shippingScreensProps($shopper, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order');
        $staff->post("/admin/orders/{$order}/shipment", ['other_carrier' => 'Aramex', 'tracking_number' => '77', 'tracking_link' => 'https://track.example.test/77']);

        expect([$before['carrier'], $before['shipment'], $before['shippingPending']])->toBe([Cx::CARRIER, 'NOT_SHIPPED', false])
            ->and([$noLink['carrier'], $noLink['trackingNumber'], $noLink['trackingLink']])->toBe(['Aramex', '77', null])
            ->and(shippingScreensProps($shopper, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order')['trackingLink'])->toBe('https://track.example.test/77');
    });
});

describe('the panel (§4.3)', function () {
    it('marks a fee to be set on the list, the order and the slip, sets it, and refuses Ship until then', function () {
        ['customer' => $customer] = shippingScreensReady();
        $order = shippingScreensPlace($customer, shippingScreensAddress($customer, shippingScreensRegion()));
        $served = shippingScreensReady();
        $other = shippingScreensPlace($served['customer'], $served['address']);
        $browser = shippingScreensStaff();
        $base = "/admin/orders/{$order}";
        $browser->post("{$base}/approve");
        $browser->post("{$base}/ship-unpaid", ['allow' => true]);

        $rows = array_column(shippingScreensProps($browser, '/admin/orders?tab=TO_SHIP', 'Sales/Admin/Orders/Index')['orders'], null, 'id');
        $page = shippingScreensProps($browser, $base, 'Sales/Admin/Orders/Show');
        $refused = $browser->post("{$base}/ship", ['other_carrier' => 'Aramex']);
        $noReason = $browser->post("{$base}/shipping-fee", ['amount' => '3000', 'reason' => '']);
        $set = $browser->post("{$base}/shipping-fee", ['amount' => '3000', 'reason' => 'Quoted by the courier']);
        $after = shippingScreensProps($browser, $base, 'Sales/Admin/Orders/Show');
        $again = $browser->post("{$base}/shipping-fee", ['amount' => '0', 'reason' => 'Waived']);

        expect($rows[$order]['shippingFeePending'])->toBeTrue()
            ->and([$page['shippingFeePending'], $page['orderedCarrierId'], $page['orderedCarrier']])->toBe([true, null, null])
            ->and(AdminBrowser::formError($refused))->toContain('shipping fee')
            ->and(AdminBrowser::formError($noReason))->not->toBeNull()
            ->and(AdminBrowser::flashed($set, 'status'))->toBe('Shipping fee set.')
            ->and([$after['shippingFeePending'], $after['shipping']['minor'], $after['total']['minor'] - $page['total']['minor']])->toBe([false, 3000, 3000])
            ->and(AdminBrowser::flashed($again, 'status'))->toBe('Shipping fee set.')
            ->and(shippingScreensProps($browser, $base, 'Sales/Admin/Orders/Show')['shipping']['minor'])->toBe(0)
            ->and(AdminBrowser::formError($browser->post("/admin/orders/{$other}/shipping-fee", ['amount' => '100', 'reason' => 'No'])))->not->toBeNull();
    });

    it('hands the slip its fee to be set', function () {
        ['customer' => $customer] = shippingScreensReady();
        $order = shippingScreensPlace($customer, shippingScreensAddress($customer, shippingScreensRegion()));
        $browser = shippingScreensStaff();

        expect(shippingScreensProps($browser, "/admin/orders/{$order}/print", 'Sales/Admin/Orders/Print')['shippingFeePending'])->toBeTrue();
    });

    it('picks out the carrier ordered, says which carriers are elsewhere, and ships and corrects with one', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $tracked = shippingScreensCarrier('Tracked', 500, [shippingScreensRegion()], 'https://carrier.example.test/t/{number}');
        $browser = shippingScreensStaff();
        $base = "/admin/orders/{$order}";
        $browser->post("{$base}/approve");
        $browser->post("{$base}/ship-unpaid", ['allow' => true]);

        $page = shippingScreensProps($browser, $base, 'Sales/Admin/Orders/Show');
        $browser->post("{$base}/ship", ['carrier_id' => $page['orderedCarrierId'], 'tracking_number' => '11', 'tracking_link' => 'https://typed.example.test/11']);
        $shipped = shippingScreensProps($browser, $base, 'Sales/Admin/Orders/Show');
        // As the dialog sends it: the carrier changed, the stored link emptied, so the pattern makes the new one.
        $corrected = $browser->post("{$base}/shipment", ['carrier_id' => $tracked, 'tracking_number' => '22', 'tracking_link' => '']);
        $after = shippingScreensProps($browser, $base, 'Sales/Admin/Orders/Show');

        expect($page['orderedCarrierId'])->toBe(Cx::serve()['carrier'])
            ->and(array_column($page['carriers'], 'inOrderCountry'))->toBe([true, true])
            ->and(array_column($page['carriers'], 'hasTrackingPattern'))->toContain(true)
            ->and([$shipped['carrierId'], $shipped['trackingLink']])->toBe([Cx::serve()['carrier'], 'https://typed.example.test/11'])
            ->and(AdminBrowser::flashed($corrected, 'status'))->toBe('Shipment corrected.')
            ->and([$after['carrierId'], $after['trackingNumber'], $after['trackingLink']])->toBe([$tracked, '22', 'https://carrier.example.test/t/22']);
    });

    it('hands the box to the order page and the slip', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $browser = shippingScreensStaff();
        $none = shippingScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show')['box'];
        shippingScreensAside(static fn () => Cx::asSuperAdmin(static fn () => app(CreateBoxHandler::class)->handle(new CreateBox(Fx::storeId('sa'), 'Big box', 1200, 800, 800, 30_000))));

        // The test sizes carry no weight or size: pack by hand, and why.
        expect($none)->toBeNull()
            ->and(shippingScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show')['box'])->toMatchArray(['byHand' => true, 'reason' => 'NO_SIZE_FACTS'])
            ->and(shippingScreensProps($browser, "/admin/orders/{$order}/print", 'Sales/Admin/Orders/Print')['box'])->toMatchArray(['byHand' => true, 'reason' => 'NO_SIZE_FACTS']);
    });
});

describe('what the review found (§4.3)', function () {
    it('keeps every digit 0-9 in a tracking number and link', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $browser = shippingScreensStaff();
        $browser->post("/admin/orders/{$order}/approve");
        $browser->post("/admin/orders/{$order}/ship-unpaid", ['allow' => true]);

        $browser->post("/admin/orders/{$order}/ship", ['other_carrier' => 'Aramex', 'tracking_number' => '٧٧', 'tracking_link' => 'https://track.example.test/٧٧']);

        expect(DB::table('sales.orders')->where('id', $order)->first(['tracking_number', 'tracking_link']))
            ->toEqual((object) ['tracking_number' => '77', 'tracking_link' => 'https://track.example.test/77']);
    });

    it('stops calling a rejected order\'s fee to be set, on the list, the page, the slip and the customer\'s pages', function () {
        ['customer' => $customer] = shippingScreensReady();
        $order = shippingScreensPlace($customer, shippingScreensAddress($customer, shippingScreensRegion()));
        $number = shippingScreensNumber($order);
        $browser = shippingScreensStaff();
        $browser->post("/admin/orders/{$order}/reject", ['reason' => 'No courier'])->assertRedirect();
        $shopper = shippingScreensShopper($customer);

        expect(shippingScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show')['shippingFeePending'])->toBeFalse()
            ->and(shippingScreensProps($browser, "/admin/orders/{$order}/print", 'Sales/Admin/Orders/Print')['shippingFeePending'])->toBeFalse()
            ->and(shippingScreensProps($browser, '/admin/orders?tab=ALL', 'Sales/Admin/Orders/Index')['orders'][0]['shippingFeePending'])->toBeFalse()
            ->and(shippingScreensProps($shopper, '/sa/en/account/orders', 'Sales/Storefront/MyOrders')['orders'][0]['shippingPending'])->toBeFalse()
            ->and(shippingScreensProps($shopper, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order')['shippingPending'])->toBeFalse();
    });

    it('says a carrier of another country is elsewhere', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $abroad = shippingScreensAside(static fn (): string => Cx::asSuperAdmin(static function (): string {
            app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
            app(AddPlaceHandler::class)->handle(new AddPlace('QA', PlaceKind::Region->value, null, 'الدوحة', 'Doha'));
            app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA'));

            return app(CreateCarrierHandler::class)->handle(new CreateCarrier(Fx::storeId('sa'), 'QA', 'بريد قطر', 'Qatar Post'));
        }));
        $browser = shippingScreensStaff();

        $rows = array_column(shippingScreensProps($browser, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show')['carriers'], null, 'id');

        expect([$rows[$abroad]['inOrderCountry'], $rows[$abroad]['countryCode']])->toBe([false, 'QA'])
            ->and($rows[Cx::serve()['carrier']]['inOrderCountry'])->toBeTrue();
    });

    it('saves Keep with the carrier picked, and with none: the order\'s own, even since deleted', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        Cx::serve(fee: 1_500);
        $own = Cx::serve(fee: 1_500)['carrier'];
        $order = shippingScreensPlace($customer, $address);
        $region = shippingScreensRegion();
        $picked = shippingScreensCarrier('Picked', 100, [$region]);
        $moved = shippingScreensAddress($customer, $region);
        // The order's carrier deleted meanwhile: its names stay on the order (§9.5 #11). Another order's place stays.
        shippingScreensAside(static fn () => Cx::asSuperAdmin(static function () use ($own): void {
            app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(Fx::storeId('sa'), $own, false));
            app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(Fx::storeId('sa'), $own));
        }));
        $browser = shippingScreensStaff();
        $edit = ['lines' => shippingScreensLines($order), 'address' => $moved, 'gift' => 'KEEP', 'reason' => 'Moved', 'shipping_fee' => 'KEEP'];

        $page = shippingScreensProps($browser, "/admin/orders/{$order}/edit", 'Sales/Admin/Orders/Edit');
        // As the page sends it with nothing picked: an empty carrier keeps the order's own.
        $browser->post("/admin/orders/{$order}/edit", [...$edit, 'carrier_id' => ''])->assertRedirect();
        $kept = DB::table('sales.orders')->where('id', $order)->first(['ordered_carrier_id', 'shipping_fee_minor']);
        $refused = $browser->post("/admin/orders/{$order}/edit", [...$edit, 'carrier_id' => $own]);
        $browser->post("/admin/orders/{$order}/edit", [...$edit, 'address' => $address, 'carrier_id' => $picked])->assertRedirect();
        $changed = DB::table('sales.orders')->where('id', $order)->first(['ordered_carrier_id', 'shipping_fee_minor']);

        expect([$page['orderedCarrierId'], $page['orderedCarrier'], array_column($page['carriers'], 'id')])->toBe([$own, Cx::CARRIER, [$picked]])
            ->and([$kept?->ordered_carrier_id, (int) $kept?->shipping_fee_minor])->toBe([$own, 1_500])
            ->and(AdminBrowser::formError($refused))->not->toBeNull()
            ->and([$changed?->ordered_carrier_id, (int) $changed?->shipping_fee_minor])->toBe([$picked, 1_500]);
    });

    it('reads the carriers for an edit with the edit job, and for Ship… with the shipping job, never for neither', function () {
        shippingScreensReady();

        expect(shippingScreensCarriersFor([P::ORDER_VIEW, P::ORDER_EDIT], true))->toBe(1)
            ->and(shippingScreensCarriersFor([P::ORDER_VIEW, P::ORDER_EDIT], false))->toBeNull()
            ->and(shippingScreensCarriersFor([P::ORDER_VIEW, P::ORDER_FULFIL], false))->toBe(1)
            ->and(shippingScreensCarriersFor([P::ORDER_VIEW, P::ORDER_FULFIL], true))->toBeNull()
            ->and(shippingScreensCarriersFor([P::ORDER_VIEW], true))->toBeNull()
            ->and(shippingScreensCarriersFor([P::ORDER_VIEW], false))->toBeNull();
    });

    it('reads the edit page in a recorded number of queries, with one carrier or three', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $browser = shippingScreensStaff();
        $count = static function () use ($browser, $order): int {
            $browser->get("/admin/orders/{$order}/edit")->assertOk();
            $seen = new ArrayObject;
            DB::listen(static function (QueryExecuted $query) use ($seen): void {
                $seen->append($query->sql);
            });
            $browser->get("/admin/orders/{$order}/edit")->assertOk();
            DB::getEventDispatcher()?->forget(QueryExecuted::class);

            return count($seen);
        };

        $one = $count();
        $region = shippingScreensRegion();
        shippingScreensCarrier('Second', 100, [$region]);
        shippingScreensCarrier('Third', 200, [$region]);

        expect($count())->toBe($one);
    });
});

describe('editing an order to another place (§4.3; gap 6.1)', function () {
    it('hands an editor the store\'s carriers without the shipping job, and a reader who ships nothing on the order page', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $editor = shippingScreensStaff([P::ORDER_VIEW, P::ORDER_EDIT]);

        $page = shippingScreensProps($editor, "/admin/orders/{$order}/edit", 'Sales/Admin/Orders/Edit');

        expect(array_column($page['carriers'], 'name'))->toBe([Cx::CARRIER])
            ->and([$page['orderedCarrierId'], $page['shippingFeePending'], $page['wayToPay']])->toBe([Cx::serve()['carrier'], false, 'STAFF_CONTACT'])
            ->and(shippingScreensProps($editor, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show')['carriers'])->toBe([]);
    });

    it('previews the carriers serving the new place, and saves today\'s fee with the one picked', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        $order = shippingScreensPlace($customer, $address);
        $region = shippingScreensRegion();
        $cheap = shippingScreensCarrier('Cheap', 1_000, [$region]);
        $dear = shippingScreensCarrier('Dear', 2_000, [$region]);
        $moved = shippingScreensAddress($customer, $region);
        $browser = shippingScreensStaff();
        $query = ['lines' => shippingScreensLines($order), 'address' => $moved, 'gift' => 'KEEP'];

        $first = $browser->get("/admin/orders/{$order}/edit/preview?".http_build_query($query))->assertOk()->json();
        $picked = $browser->get("/admin/orders/{$order}/edit/preview?".http_build_query([...$query, 'carrier_id' => $dear]))->assertOk()->json();
        $browser->post("/admin/orders/{$order}/edit", [...$query, 'reason' => 'Moved', 'carrier_id' => $dear, 'shipping_fee' => 'TODAY'])->assertRedirect();
        $row = DB::table('sales.orders')->where('id', $order)->first(['ordered_carrier_id', 'shipping_fee_minor']);

        expect([$first['placeChanged'], array_column($first['carriers'], 'id'), $first['carrierId']])->toBe([true, [$cheap, $dear], $cheap])
            ->and([$picked['carrierId'], $picked['shipping']['minor']])->toBe([$dear, 2_000])
            ->and([$row?->ordered_carrier_id, (int) $row?->shipping_fee_minor])->toBe([$dear, 2_000]);
    });

    it('keeps the order\'s own fee, makes it to be set at a place no carrier serves, and shows no place change at the same place', function () {
        ['customer' => $customer, 'address' => $address] = shippingScreensReady();
        Cx::serve(fee: 1_500);
        $order = shippingScreensPlace($customer, $address);
        $region = shippingScreensRegion();
        shippingScreensCarrier('Cheap', 100, [$region]);
        $moved = shippingScreensAddress($customer, $region);
        $unserved = shippingScreensAddress($customer, shippingScreensRegion('Unserved'));
        $same = shippingScreensAddress($customer, Cx::serve(fee: 1_500)['region']);
        $browser = shippingScreensStaff();
        $lines = ['lines' => shippingScreensLines($order), 'gift' => 'KEEP'];
        $read = fn (array $more): array => $browser->get("/admin/orders/{$order}/edit/preview?".http_build_query([...$lines, ...$more]))->assertOk()->json();

        $kept = $read(['address' => $moved, 'shipping_fee' => 'KEEP']);
        $pending = $read(['address' => $unserved]);
        $here = $read(['address' => $same]);
        $refused = $browser->get("/admin/orders/{$order}/edit/preview?".http_build_query([...$lines, 'address' => $same, 'shipping_fee' => 'KEEP']));

        expect([$kept['placeChanged'], $kept['shipping']['minor'], $kept['shippingPending']])->toBe([true, 1_500, false])
            ->and([$pending['placeChanged'], $pending['carriers'], $pending['carrierId'], $pending['shippingPending']])->toBe([true, [], null, true])
            ->and([$here['placeChanged'], $here['carriers']])->toBe([false, []])
            ->and($refused->status())->toBe(422);
    });
});
