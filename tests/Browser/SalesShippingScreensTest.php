<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountry;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountryHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Sales\Application\SalesPermissions as P;
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
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The screens' side of shipping in a real browser (sales.md §4.3): a carrier chosen at checkout and placed; a place no
| carrier serves, through to the fee set, shipped with a link and corrected; an edit to another place; all at a phone's
| width with nothing scrolling sideways.
|
| Deliberately no RefreshDatabase, as the other browser tests: every region and carrier made here goes again after each
| test, so later files see the store as it was.
*/

/** The regions and carriers each test made, gone in afterEach. */
$GLOBALS['salesShipping'] = ['regions' => [], 'carriers' => []];

afterEach(function () {
    Cx::asSuperAdmin(function (): void {
        foreach ($GLOBALS['salesShipping']['carriers'] as $carrier) {
            app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(Fx::storeId('sa'), $carrier, false));
            app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(Fx::storeId('sa'), $carrier));
        }

        foreach ($GLOBALS['salesShipping']['regions'] as $region) {
            app(RetirePlaceHandler::class)->handle(new RetirePlace($region));
        }
    });
    $GLOBALS['salesShipping'] = ['regions' => [], 'carriers' => []];
    Cx::unserve();
});

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CatalogProducts::numberFrom((int) substr((string) hrtime(true), -8));
});

function salesShippingAside(Closure $work): mixed
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        return $work();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

function salesShippingRegion(): string
{
    $name = 'Region '.hrtime(true);
    $region = salesShippingAside(static fn (): string => Cx::asSuperAdmin(static fn (): string => app(AddPlaceHandler::class)->handle(new AddPlace('SA', PlaceKind::Region->value, null, $name.' ع', $name))));
    $GLOBALS['salesShipping']['regions'][] = $region;

    return $region;
}

/** @param  list<string>  $places */
function salesShippingCarrier(string $nameEn, int $fee, array $places): string
{
    $name = $nameEn.' '.hrtime(true);
    $carrier = salesShippingAside(static fn (): string => Cx::asSuperAdmin(static function () use ($name, $fee, $places): string {
        $store = Fx::storeId('sa');
        $carrier = app(CreateCarrierHandler::class)->handle(new CreateCarrier($store, 'SA', 'ناقل '.$name, $name));
        app(SavePriceGroupHandler::class)->handle(new SavePriceGroup($store, $carrier, null, $fee, $places, PriceGroup::versionOf(app(PriceGroupRepository::class)->ofCarrier($carrier))));

        return $carrier;
    }));
    $GLOBALS['salesShipping']['carriers'][] = $carrier;

    return $carrier;
}

function salesShippingAddress(string $customer, string $region): string
{
    return salesShippingAside(static function () use ($customer, $region): string {
        Fx::actAsCustomer($customer);

        return app(SaveAddressHandler::class)->handle(new SaveAddress(
            storeId: Fx::storeId('sa'), label: 'Second', recipientName: 'Sara Ali',
            fields: ['administrative_area' => 'Somewhere', 'city' => 'Somewhere', 'district' => 'Middle', 'street' => 'Olaya Street', 'building' => '9'],
            countryCode: 'SA', regionId: $region,
        ));
    });
}

function salesShippingShopper(string $customer): mixed
{
    $email = (string) DB::table('access.customers')->where('id', $customer)->value('email');
    $page = visit('/sa/en/sign-in')->type('#email', $email)->type('#password', Fx::CUSTOMER_PASSWORD)->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();

    return $page;
}

/** @param  list<string>  $jobs */
function salesShippingPanel(array $jobs = [P::ORDER_VIEW, P::ORDER_APPROVE, P::ORDER_FULFIL, P::ORDER_EDIT, P::PAYMENT_RECORD]): mixed
{
    $staffId = Fx::staffWith($jobs, ['sa']);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
    $page = visit('/admin/sign-in')->type('#email', $email)->type('#password', Fx::STAFF_PASSWORD)->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/admin/sign-in/code'"))->toBeTrue();
    $page->type('#code', RecordingSecurityMessages::installed()->lastCode())->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/admin'"))->toBeTrue();

    return $page;
}

function salesShippingWide(mixed $page): string
{
    return (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');
}

function salesShippingText(mixed $page, string $selector): string
{
    return (string) $page->script('document.querySelector('.json_encode($selector).')?.textContent ?? ""');
}

it('chooses a carrier at checkout, keeps it in the summary, and places the order with it; the cart says shipping comes later', function () {
    ['customer' => $customer] = salesShippingAside(static fn (): array => Cx::ready());
    $dear = salesShippingCarrier('Dear', 4_000, [Cx::serve()['region']]);

    $page = salesShippingShopper($customer);
    $page->resize(375, 812);
    $page->navigate('/sa/en/cart', BROWSER_PAGE_LOAD);
    expect(salesShippingText($page, '[data-test="summary-shipping"]'))->toContain('Worked out at checkout');

    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD)->assertPresent('[data-test="checkout-delivery"]');
    expect(salesShippingWide($page))->toBe('', 'checkout scrolls sideways');

    $page->click("[data-test=\"carrier-{$dear}\"]");
    expect(browserUntil($page, "location.search.includes('carrier={$dear}')"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"checkout-summary\"]').textContent.includes('40.00')"))->toBeTrue();

    $page->click('[data-test="place-order"]');
    expect(browserUntil($page, "location.pathname.endsWith('/placed')"))->toBeTrue()
        ->and((string) DB::table('sales.orders')->where('customer_id', $customer)->value('ordered_carrier_id'))->toBe($dear);
});

it('takes an order with no carrier from checkout through the fee, Ship with a link and a correction, to Track', function () {
    ['customer' => $customer] = salesShippingAside(static fn (): array => Cx::ready());
    $address = salesShippingAddress($customer, salesShippingRegion());

    $shop = salesShippingShopper($customer);
    $shop->navigate("/sa/en/checkout?address={$address}", BROWSER_PAGE_LOAD)->assertPresent('[data-test="delivery-pending"]');
    expect(salesShippingText($shop, '[data-test="summary-total-label"]'))->toContain('Total before shipping')
        ->and(salesShippingText($shop, '[data-test="place-order"]'))->not->toContain('·');
    $shop->click('[data-test="place-order"]');
    expect(browserUntil($shop, "location.pathname.endsWith('/placed')"))->toBeTrue();
    $shop->assertPresent('[data-test="placed-fee-note"]');

    $row = DB::table('sales.orders')->where('customer_id', $customer)->first(['id', 'number']);
    $panel = salesShippingPanel();
    $panel->resize(375, 812);
    $panel->navigate("/admin/orders/{$row?->id}", BROWSER_PAGE_LOAD)->assertPresent('[data-test="order-fee-pending"]')->assertPresent('[data-test="shipping-pending"]');
    $panel->click('[data-test="approve"]');
    expect(browserUntil($panel, "document.body.innerText.includes('Order approved.')"))->toBeTrue();

    // The fee is next: the main button, its dialog showing the total as it is typed.
    $panel->click('[data-test="set-fee"]')->type('#amount', '٣٠٫٥')->type('#reason', 'Quoted by the courier');
    expect(browserUntil($panel, "(document.querySelector('[data-test=\"fee-total\"]')?.textContent ?? '').length > 0"))->toBeTrue()
        ->and(salesShippingWide($panel))->toBe('', 'the fee dialog scrolls sideways');
    $panel->click('[data-test="confirm-fee"]');
    expect(browserUntil($panel, "document.body.innerText.includes('Shipping fee set.')"))->toBeTrue()
        ->and((int) DB::table('sales.orders')->where('id', $row?->id)->value('shipping_fee_minor'))->toBe(3_050);

    DB::table('sales.orders')->where('id', $row?->id)->update(['may_ship_unpaid' => true]);
    $panel->navigate("/admin/orders/{$row?->id}", BROWSER_PAGE_LOAD)->click('[data-test="ship"]');
    expect(browserUntil($panel, "!!document.querySelector('#carrier')"))->toBeTrue();
    $panel->select('#carrier', 'OTHER')->type('#other_carrier', 'Aramex')->type('#tracking_number', '77')->type('#tracking_link', 'https://track.example.test/77')->click('[data-test="confirm-ship"]');
    expect(browserUntil($panel, "document.body.innerText.includes('Order shipped.')"))->toBeTrue();

    // Changing the number empties the stored link: a typed link would otherwise always win (§1.5).
    $panel->click('[data-test="order-actions"]')->click('[data-test="correct-shipment"]');
    expect(browserUntil($panel, "document.querySelector('#tracking_link')?.value === 'https://track.example.test/77'"))->toBeTrue();
    $long = str_repeat('8', 100);
    $panel->type('#tracking_number', $long);
    expect(browserUntil($panel, "document.querySelector('#tracking_link').value === ''"))->toBeTrue();
    $panel->type('#tracking_link', 'https://track.example.test/88')->click('[data-test="confirm-correct"]');
    expect(browserUntil($panel, "document.body.innerText.includes('Shipment corrected.')"))->toBeTrue()
        ->and(salesShippingWide($panel))->toBe('', 'a long tracking number scrolls the order sideways');

    $shop->resize(375, 812);
    $shop->navigate("/sa/en/account/orders/{$row?->number}", BROWSER_PAGE_LOAD);
    expect(salesShippingWide($shop))->toBe('', 'a long tracking number scrolls the customer\'s order sideways');
    expect((string) $shop->script("document.querySelector('[data-test=\"track\"]').getAttribute('href')"))->toBe('https://track.example.test/88');
});

it('edits an order to another place: the carriers serving it with today\'s fee, the preview stale until asked again', function () {
    ['customer' => $customer, 'address' => $address] = salesShippingAside(static fn (): array => Cx::ready());
    $order = salesShippingAside(static function () use ($customer, $address): string {
        Fx::actAsCustomer($customer);

        return Cx::place(Cx::start($address)->id)->id;
    });
    $region = salesShippingRegion();
    salesShippingCarrier('Cheap', 1_000, [$region]);
    $dear = salesShippingCarrier('Dear', 2_000, [$region]);
    $moved = salesShippingAddress($customer, $region);

    $page = salesShippingPanel();
    $page->resize(375, 812);
    $page->navigate("/admin/orders/{$order}/edit", BROWSER_PAGE_LOAD)->select('#edit-address', $moved)->click('[data-test="preview-edit"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"new-place\"]')"))->toBeTrue()
        ->and(salesShippingWide($page))->toBe('', 'the edit page scrolls sideways');

    // The order's own fee (0 for the test carrier), then back to today's.
    $page->click('[data-test="fee-KEEP"]')->assertPresent('#keep-carrier')->click('[data-test="preview-edit"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"preview-shipping\"]').textContent.trim().endsWith('0.00 SAR') || document.querySelector('[data-test=\"preview-shipping\"]').textContent.includes('0.00')"))->toBeTrue();
    $page->click('[data-test="fee-TODAY"]');

    $page->click("[data-test=\"carrier-{$dear}\"]");
    expect(browserUntil($page, "document.querySelector('[data-test=\"confirm-edit\"]')?.getAttribute('aria-disabled') === 'true' || document.querySelector('[data-test=\"confirm-edit\"]')?.disabled === true"))->toBeTrue();
    $page->click('[data-test="preview-edit"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"preview-shipping\"]').textContent.includes('20.00')"))->toBeTrue();

    $page->type('#edit-reason', 'Moved house')->click('[data-test="confirm-edit"]');
    expect(browserUntil($page, "document.body.innerText.includes('Order edited.')"))->toBeTrue()
        ->and((string) DB::table('sales.orders')->where('id', $order)->value('ordered_carrier_id'))->toBe($dear);
});

it('shows a reader with only the shipping job Ship… out of reach while the fee is to be set', function () {
    ['customer' => $customer] = salesShippingAside(static fn (): array => Cx::ready());
    $address = salesShippingAddress($customer, salesShippingRegion());
    $order = salesShippingAside(static function () use ($customer, $address): string {
        Fx::actAsCustomer($customer);

        return Cx::place(Cx::start($address)->id)->id;
    });
    Cx::approveWaiting($customer);

    $page = salesShippingPanel([P::ORDER_VIEW, P::ORDER_FULFIL]);
    $page->navigate("/admin/orders/{$order}", BROWSER_PAGE_LOAD)->assertPresent('[data-test="ship"]');

    expect(browserUntil($page, "document.querySelector('[data-test=\"ship\"]').getAttribute('aria-disabled') === 'true' || document.querySelector('[data-test=\"ship\"]').disabled === true"))->toBeTrue()
        ->and((string) $page->script('document.querySelector(\'[data-test="set-fee"]\') === null ? "none" : "there"'))->toBe('none');
});

it('sends an address another store serves to that store, with no summary', function () {
    ['customer' => $customer] = salesShippingAside(static fn (): array => Cx::ready());
    $name = 'Doha '.hrtime(true);
    $doha = salesShippingAside(static fn (): string => Cx::asSuperAdmin(static function () use ($name): string {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));

        return app(AddPlaceHandler::class)->handle(new AddPlace('QA', PlaceKind::Region->value, null, $name.' ع', $name));
    }));
    $GLOBALS['salesShipping']['regions'][] = $doha;
    $qatar = salesShippingAside(static function () use ($customer, $doha): string {
        Fx::actAsCustomer($customer);

        return app(SaveAddressHandler::class)->handle(new SaveAddress(
            storeId: Fx::storeId('sa'), label: 'Doha', recipientName: 'Sara Ali',
            fields: ['administrative_area' => 'Somewhere', 'city' => 'Somewhere', 'district' => 'Middle', 'street' => 'Corniche', 'building' => '9'],
            countryCode: 'QA', regionId: $doha,
        ));
    });
    salesShippingAside(static fn () => Cx::asSuperAdmin(static fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('ae', 'QA'))));

    try {
        $page = salesShippingShopper($customer);
        $page->resize(375, 812);
        $page->navigate("/sa/en/checkout?address={$qatar}", BROWSER_PAGE_LOAD)->assertPresent('[data-test="other-store"]')->assertMissing('[data-test="checkout-summary"]');

        expect((string) $page->script("document.querySelector('[data-test=\"other-store-link\"]').getAttribute('href')"))->toEndWith('/ae/en')
            ->and(salesShippingWide($page))->toBe('', 'the other store\'s notice scrolls sideways');
    } finally {
        salesShippingAside(static fn () => Cx::asSuperAdmin(static fn () => app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('ae', 'QA'))));
    }
});
