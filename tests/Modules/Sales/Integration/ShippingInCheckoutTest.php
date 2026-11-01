<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CorrectShipment\CorrectShipment;
use Modules\Sales\Application\Command\CorrectShipment\CorrectShipmentHandler;
use Modules\Sales\Application\Command\EditOrder\EditOrder;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
use Modules\Sales\Application\Command\SetShippingFee\SetShippingFee;
use Modules\Sales\Application\Command\SetShippingFee\SetShippingFeeHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\StartCheckout\StartCheckout;
use Modules\Sales\Application\Command\StartCheckout\StartCheckoutHandler;
use Modules\Sales\Application\Query\ListCarriers\ListCarriers;
use Modules\Sales\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Sales\Application\Query\PrintOrder\PrintOrder;
use Modules\Sales\Application\Query\PrintOrder\PrintOrderHandler;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrder;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Application\Quote\QuoteShipping;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\AddressInOtherStore;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotEditable;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Exception\ShippingFeeNotSet;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Enums\WayToPay;
use Modules\Shipping\Application\Command\CreateBox\CreateBox;
use Modules\Shipping\Application\Command\CreateBox\CreateBoxHandler;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrier;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrierHandler;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroup;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroupHandler;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/*
| Shipping in the checkout and the orders (sales.md §9.5 #1–#3, #11, #12; stage 7 step 6, the owner's gap
| answers 6.1–6.9): the quote asks Shipping — served, not covered, outside, another store's — the carrier
| picked and snapshotted, the fee to be set by staff, marking shipped with a carrier and a link, correcting
| it, and a staff address change quoted again. Every helper is named after this file's subject.
*/

/** A region of Saudi Arabia beside the test one; no carrier prices it unless a test does. */
function shipCheckoutRegion(string $nameEn = 'Other Region'): string
{
    return Cx::asSuperAdmin(static fn (): string => app(AddPlaceHandler::class)->handle(new AddPlace('SA', PlaceKind::Region->value, null, $nameEn.' ع', $nameEn)));
}

/**
 * A switched-on carrier of the Saudi store pricing these places at `$fee`.
 *
 * @param  list<string>  $places
 */
function shipCheckoutCarrier(string $nameEn, int $fee, array $places, ?string $pattern = null): string
{
    return Cx::asSuperAdmin(static function () use ($nameEn, $fee, $places, $pattern): string {
        $store = Fx::storeId('sa');
        $carrier = app(CreateCarrierHandler::class)->handle(new CreateCarrier($store, 'SA', 'ناقل '.$nameEn, $nameEn, trackingPattern: $pattern));
        app(SavePriceGroupHandler::class)->handle(new SavePriceGroup($store, $carrier, null, $fee, $places, PriceGroup::versionOf(app(PriceGroupRepository::class)->ofCarrier($carrier))));

        return $carrier;
    });
}

/** Another address of the customer in the Saudi store — or this one changed: in this region, or in this country typed. */
function shipCheckoutAddress(string $customer, ?string $region, string $country = 'SA', ?string $addressId = null): string
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsCustomer($customer);

    try {
        return app(SaveAddressHandler::class)->handle(new SaveAddress(
            storeId: Fx::storeId('sa'), label: 'Second', recipientName: 'Sara Ali',
            fields: ['administrative_area' => 'Somewhere', 'city' => 'Somewhere', 'district' => 'Middle', 'street' => 'Olaya Street', 'building' => '9'],
            addressId: $addressId, countryCode: $country, regionId: $region,
        ));
    } finally {
        if ($previous !== null) {
            app()->scoped(ActorContext::class, $previous);
            app()->forgetScopedInstances();
        }
    }
}

/** Staff who approve, fulfil and edit the Saudi store's orders, acting. */
function shipCheckoutStaff(): void
{
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::ORDER_EDIT], ['sa']));
}

/** @return array<string, mixed> */
function shipCheckoutRow(string $order): array
{
    return (array) DB::table('sales.orders')->where('id', $order)->first();
}

/** The order placed from this address, approved and paid in full, staff acting. */
function shipCheckoutPlaced(string $address, ?string $carrier = null): string
{
    $order = Cx::place(app(StartCheckoutHandler::class)->handle(
        new StartCheckout(Fx::storeId('sa'), $address, false, null, 'STAFF_CONTACT', $carrier),
    )->id)->id;
    shipCheckoutStaff();
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);

    return $order;
}

/**
 * The order's lines as they are, for an edit that changes only its address.
 *
 * @return list<array{variantId: string, mode: string, quantity: int}>
 */
function shipCheckoutLines(string $order): array
{
    return array_values(array_map(static fn (stdClass $line): array => ['variantId' => (string) $line->variant_id, 'mode' => (string) $line->sale_mode, 'quantity' => (int) $line->quantity],
        DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->get()->all()));
}

describe('the quote (§1.2 step 9)', function () {
    it('picks the cheapest carrier serving the address, or the one the customer chose, and snapshots its names', function () {
        Cx::serve('sa', 30_00);
        ['address' => $address] = Cx::ready();
        $region = Cx::serve('sa', 30_00)['region'];
        $cheaper = shipCheckoutCarrier('Fast', 20_00, [$region]);

        $cheapest = Cx::start($address);
        $chosen = app(StartCheckoutHandler::class)->handle(
            new StartCheckout(Fx::storeId('sa'), $address, false, null, 'STAFF_CONTACT', Cx::serve('sa', 30_00)['carrier']),
        );

        expect([$cheapest->shipping->coverage, $cheapest->shipping->carrierId, $cheapest->shipping->carrierEn, $cheapest->totals->shipping->minorUnits, $cheapest->shipping->carrierChanged])
            ->toBe([QuoteShipping::SERVED, $cheaper, 'Fast', 20_00, false])
            ->and(array_column($cheapest->shipping->options, 'fee'))->toBe([20_00, 30_00])
            ->and([$chosen->shipping->carrierEn, $chosen->totals->shipping->minorUnits])->toBe([Cx::CARRIER, 30_00]);

        $order = shipCheckoutRow(Cx::place($chosen->id)->id);
        expect([$order['ordered_carrier_en'], $order['ordered_carrier_ar'], (int) $order['shipping_fee_minor'], (bool) $order['shipping_fee_pending']])
            ->toBe([Cx::CARRIER, 'ناقل الاختبار', 30_00, false]);
    });

    it('picks the cheapest and says so when the carrier asked for no longer serves the address (gap 6.9)', function () {
        ['address' => $address] = Cx::ready();
        $elsewhere = shipCheckoutCarrier('Elsewhere', 5_00, [shipCheckoutRegion()]);

        $quote = app(StartCheckoutHandler::class)->handle(
            new StartCheckout(Fx::storeId('sa'), $address, false, null, 'STAFF_CONTACT', $elsewhere),
        );

        expect([$quote->shipping->carrierEn, $quote->shipping->carrierChanged])->toBe([Cx::CARRIER, true]);
    });

    it('leaves the fee to staff where no carrier serves the place, and lets staff contact be the only way to pay (§9.5 #2)', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready('company');
        // An approved company of a store with a bank account (b2b.md): a bank transfer offered where shipping is known.
        $b2b = Mockery::mock(B2BApi::class);
        $b2b->shouldReceive('isApproved')->andReturn(true);
        $bankAccount = Mockery::mock(PaymentsApi::class);
        $bankAccount->shouldReceive('bankAccount')->andReturn(new BankAccountDto('SA00 0000', 'Bank', 'TouchWood'));
        app()->instance(PaymentsApi::class, $bankAccount);
        app()->instance(B2BApi::class, $b2b);
        $unserved = shipCheckoutAddress($customer, shipCheckoutRegion());

        $served = Cx::start($address);
        $refused = Pr::thrown(fn () => Cx::start($unserved, 'BANK_TRANSFER'), WayToPayNotOffered::class);
        $quote = Cx::start($unserved);
        $placed = shipCheckoutRow(Cx::place($quote->id)->id);

        expect($served->waysToPay)->toBe([WayToPay::BankTransfer, WayToPay::StaffContact])
            ->and($refused->type())->toBe('sales.way_to_pay_not_offered')
            ->and([$quote->shipping->coverage, $quote->shipping->carrierId, $quote->totals->shipping->minorUnits, $quote->waysToPay])
            ->toBe([QuoteShipping::NOT_COVERED, null, 0, [WayToPay::StaffContact]])
            ->and([(bool) $placed['shipping_fee_pending'], (int) $placed['shipping_fee_minor'], $placed['ordered_carrier_id'], $placed['way_to_pay']])
            ->toBe([true, 0, null, 'STAFF_CONTACT']);
    });

    it('quotes a country no store ships to as OUTSIDE, and refuses one another store ships to, naming it', function () {
        ['customer' => $customer] = Cx::ready();
        // Qatar with a place list: a store may take it as a country only then (platform.md, stage 7 step 1).
        $doha = Cx::asSuperAdmin(static function (): string {
            app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));

            return app(AddPlaceHandler::class)->handle(new AddPlace('QA', PlaceKind::Region->value, null, 'الدوحة', 'Doha'));
        });
        $qatar = shipCheckoutAddress($customer, $doha, 'QA');

        $outside = Cx::start($qatar);
        Cx::asSuperAdmin(static fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('ae', 'QA')));
        $refused = Pr::thrown(fn () => Cx::start($qatar), AddressInOtherStore::class);

        expect([$outside->shipping->coverage, $outside->shipping->pending()])->toBe([QuoteShipping::OUTSIDE, true])
            ->and([$refused->storeCode, $refused->type()])->toBe(['ae', 'sales.address_in_other_store'])
            ->and($refused->storeEn)->not->toBe('');
    });

    it('charges no VAT on the shipping: the owner\'s 1,000 + 150 + 30 = 1,180 (§9.5 #1)', function () {
        Cx::serve('sa', 30_00);
        ['address' => $address, 'variants' => $variants] = Cx::ready();
        DB::table('sales.cart_lines')->delete();
        Cx::add($variants[0], 10);

        $quote = Cx::start($address);

        expect([$quote->totals->goodsTotal->minorUnits, $quote->totals->vat->minorUnits, $quote->totals->shipping->minorUnits, $quote->totals->orderTotal->minorUnits])
            ->toBe([1_000_00, 150_00, 30_00, 1_180_00]);
    });

    it('asks Shipping in a recorded number of queries, however many carriers serve the place', function () {
        Cx::serve('sa', 30_00);
        ['address' => $address] = Cx::ready();
        $count = static function () use ($address): int {
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            Cx::start($address);

            return $queries;
        };

        // Warm: a first quote fills the caches it reads.
        $count();
        $one = $count();
        shipCheckoutCarrier('Fast', 20_00, [Cx::serve('sa', 30_00)['region']]);
        shipCheckoutCarrier('Slow', 40_00, [Cx::serve('sa', 30_00)['region']]);
        $count();

        // Measured 2026-10-30 (stage 7 step 6): the whole quote, Shipping's part included — the country's store,
        // the place, the prices on it, the carriers, the store-wide free-above amount. A target, not a cap
        // (owner, 2026-10-19).
        expect($count())->toBe($one)
            ->and($one)->toBe(22);
    });

    it('refuses to place a quote whose address moved to another place since (gap 6.8)', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $quote = Cx::start($address);
        shipCheckoutAddress($customer, shipCheckoutRegion(), addressId: $address);
        Fx::actAsCustomer($customer);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('address');
    });
});

describe('the fee to be set (gap 6.2)', function () {
    it('is refused shipping until staff set it, then ships; the fee adds to the total with no VAT', function () {
        ['customer' => $customer] = Cx::ready();
        $order = shipCheckoutPlaced(shipCheckoutAddress($customer, shipCheckoutRegion()));
        $before = shipCheckoutRow($order);

        expect(fn () => app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA')))->toThrow(ShippingFeeNotSet::class);

        app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 40_00, 'Courier quoted 40'));
        $after = shipCheckoutRow($order);

        expect([(int) $after['shipping_fee_minor'], (bool) $after['shipping_fee_pending'], (int) $after['order_total_minor'], (int) $after['vat_minor'], $after['shipping_rule_free_above_minor']])
            ->toBe([40_00, false, (int) $before['order_total_minor'] + 40_00, (int) $before['vat_minor'], null])
            ->and(DB::table('sales.order_edits')->where('order_id', $order)->count())->toBe(1);

        // Set wrongly: set again until it ships, the total moving by the difference.
        app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 25_00, 'Courier corrected to 25'));
        expect([(int) shipCheckoutRow($order)['shipping_fee_minor'], (int) shipCheckoutRow($order)['order_total_minor']])->toBe([25_00, (int) $before['order_total_minor'] + 25_00]);

        // The fee raised what is due: paid again in full, then it ships, and the fee is final.
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA'));
        expect(shipCheckoutRow($order)['shipment_status'])->toBe('SHIPPED')
            ->and(fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 10_00, 'After')))->toThrow(NotEditable::class);
    });

    it('is refused for an order with a carrier\'s quoted fee, and for one cancelled', function () {
        ['address' => $address, 'customer' => $customer, 'variants' => $variants] = Cx::ready();
        $served = shipCheckoutPlaced($address);
        $unserved = shipCheckoutAddress($customer, shipCheckoutRegion());
        Fx::actAsCustomer($customer);
        Cx::add($variants[0]);
        $cancelled = shipCheckoutPlaced($unserved);
        DB::table('sales.orders')->where('id', $cancelled)->update(['status' => 'CANCELLED', 'cancel_reason' => 'BY_STAFF', 'cancel_other' => 'Test', 'cancelled_by' => Fx::staff(), 'cancelled_at' => now()]);

        expect(fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($served, 10_00, 'Change')))->toThrow(InvalidOrderChange::class)
            ->and(fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($cancelled, 10_00, 'Change')))->toThrow(NotEditable::class)
            ->and((int) shipCheckoutRow($served)['shipping_fee_minor'])->toBe(0);
    });

    it('is not blocked by a size ticked "reduced in the provider" (gap 6.2)', function () {
        ['customer' => $customer, 'variants' => $variants] = Cx::ready();
        $order = shipCheckoutPlaced(shipCheckoutAddress($customer, shipCheckoutRegion()));
        DB::table('sales.order_ticks')->insert(['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'variant_id' => $variants[0], 'ticked_by' => Fx::staff(), 'ticked_at' => now()]);

        app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 15_00, 'Set'));

        expect((int) shipCheckoutRow($order)['shipping_fee_minor'])->toBe(15_00);
    });

    it('takes 0 as a real fee, and refuses one below 0 or past the limit', function (int $amount, bool $taken) {
        ['customer' => $customer] = Cx::ready();
        $order = shipCheckoutPlaced(shipCheckoutAddress($customer, shipCheckoutRegion()));
        $set = fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, $amount, 'Set'));

        $taken ? $set() : expect($set)->toThrow(InvalidSalesAttribute::class);

        expect((bool) shipCheckoutRow($order)['shipping_fee_pending'])->toBe(! $taken);
    })->with([
        'nothing' => [0, true],
        'the limit' => [100_000_000, true],
        'below 0' => [-1, false],
        'past the limit' => [100_000_001, false],
    ]);
});

describe('marking shipped (§9.5 #3; gaps 6.3–6.5)', function () {
    it('ships with one of the store\'s carriers, its names kept and its pattern making the link', function () {
        ['address' => $address] = Cx::ready();
        $carrier = shipCheckoutCarrier('Tracked', 15_00, [Cx::serve()['region']], 'https://track.example/{number}');
        $order = shipCheckoutPlaced($address);

        app(ShipOrderHandler::class)->handle(new ShipOrder($order, $carrier, trackingNumber: 'AB 1'));
        $row = shipCheckoutRow($order);

        expect([$row['carrier_id'], $row['carrier_en'], $row['carrier_ar'], $row['tracking_number'], $row['tracking_link']])
            ->toBe([$carrier, 'Tracked', 'ناقل Tracked', 'AB 1', 'https://track.example/AB%201']);
    });

    it('takes a typed link over the pattern, and "Other carrier" by name with no id', function () {
        ['address' => $address] = Cx::ready();
        $carrier = shipCheckoutCarrier('Tracked', 15_00, [Cx::serve()['region']], 'https://track.example/{number}');
        $first = shipCheckoutPlaced($address);

        app(ShipOrderHandler::class)->handle(new ShipOrder($first, $carrier, trackingNumber: 'X1', trackingLink: 'https://typed.example/x1'));
        expect(shipCheckoutRow($first)['tracking_link'])->toBe('https://typed.example/x1');

        ['address' => $second] = Cx::ready();
        $other = shipCheckoutPlaced($second);
        app(ShipOrderHandler::class)->handle(new ShipOrder($other, otherCarrier: 'Qatar Post', trackingNumber: 'Q9'));
        $row = shipCheckoutRow($other);

        expect([$row['carrier_id'], $row['carrier_en'], $row['carrier_ar'], $row['tracking_link']])->toBe([null, 'Qatar Post', 'Qatar Post', null]);
    });

    it('refuses a link that is not https, a carrier of another store, both a carrier and a name, and neither', function (Closure $command) {
        ['address' => $address] = Cx::ready();
        $order = shipCheckoutPlaced($address);

        expect(fn () => app(ShipOrderHandler::class)->handle($command($order)))->toThrow(InvalidSalesAttribute::class)
            ->and(shipCheckoutRow($order)['shipment_status'])->toBe('NOT_SHIPPED');
    })->with([
        'a plain http link' => [fn (string $order) => new ShipOrder($order, otherCarrier: 'SMSA', trackingLink: 'http://track.example/1')],
        'not an address' => [fn (string $order) => new ShipOrder($order, otherCarrier: 'SMSA', trackingLink: 'https://')],
        'a link too long' => [fn (string $order) => new ShipOrder($order, otherCarrier: 'SMSA', trackingLink: 'https://t.example/'.str_repeat('a', 483))],
        'another store\'s carrier' => [fn (string $order) => new ShipOrder($order, Cx::asSuperAdmin(static fn (): string => app(CreateCarrierHandler::class)->handle(new CreateCarrier(Fx::storeId('ae'), 'AE', 'ناقل الإمارات', 'Emirates Carrier'))))],
        'a carrier that is no carrier' => [fn (string $order) => new ShipOrder($order, strtolower((string) Str::ulid()))],
        'a tracking number too long' => [fn (string $order) => new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: str_repeat('1', 101))],
        'a tracking number on two lines' => [fn (string $order) => new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: 'A1
B2')],
        'another carrier named too long' => [fn (string $order) => new ShipOrder($order, otherCarrier: str_repeat('a', 101))],
        'a carrier and a name' => [fn (string $order) => new ShipOrder($order, (string) DB::table('shipping.carriers')->value('id'), 'SMSA')],
        'neither' => [fn (string $order) => new ShipOrder($order)],
    ]);

    it('lists the store\'s carriers for Ship…, the order\'s country first (gap 6.3)', function () {
        Cx::ready();
        Cx::asSuperAdmin(static function (): void {
            app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
            app(AddPlaceHandler::class)->handle(new AddPlace('QA', PlaceKind::Region->value, null, 'الدوحة', 'Doha'));
            app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA'));
            app(CreateCarrierHandler::class)->handle(new CreateCarrier(Fx::storeId('sa'), 'QA', 'بريد قطر', 'Qatar Post'));
        });
        shipCheckoutStaff();
        $list = static fn (?string $country): array => array_map(
            static fn ($carrier): string => $carrier->nameEn,
            app(ListCarriersHandler::class)->handle(new ListCarriers(Fx::storeId('sa'), $country)),
        );

        expect($list('QA'))->toBe(['Qatar Post', Cx::CARRIER])
            ->and($list('SA'))->toBe([Cx::CARRIER, 'Qatar Post']);
    });

    it('lets staff correct the carrier, number or link while it is shipped, and never before or after', function () {
        ['address' => $address] = Cx::ready();
        $order = shipCheckoutPlaced($address);
        $correct = fn () => app(CorrectShipmentHandler::class)->handle(new CorrectShipment($order, otherCarrier: 'Aramex', trackingNumber: 'N2', trackingLink: 'https://aramex.example/N2'));

        expect($correct)->toThrow(InvalidOrderChange::class);

        app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: 'N1'));
        $correct();
        $row = shipCheckoutRow($order);

        expect([$row['carrier_en'], $row['tracking_number'], $row['tracking_link']])->toBe(['Aramex', 'N2', 'https://aramex.example/N2'])
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'SHIPMENT_CORRECTED')->count())->toBe(1)
            ->and(Fx::audits('sales.order.shipment_corrected', $order))->toBe(1);

        DB::table('sales.orders')->where('id', $order)->update(['shipment_status' => 'DELIVERED', 'status' => 'COMPLETED', 'delivered_at' => now(), 'return_deadline_at' => now()->addDays(14)]);
        expect($correct)->toThrow(InvalidOrderChange::class);
    });
});

describe('a staff address change (gap 6.1)', function () {
    it('quotes the new place today: the carrier serving it, its fee and free-above', function () {
        Cx::serve('sa', 30_00);
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $region = shipCheckoutRegion();
        $fast = shipCheckoutCarrier('Fast', 10_00, [$region]);
        $order = shipCheckoutPlaced($address);
        $moved = shipCheckoutAddress($customer, $region);

        app(EditOrderHandler::class)->handle(new EditOrder($order, 'New address', shipCheckoutLines($order), $moved));
        $row = shipCheckoutRow($order);

        expect([$row['ordered_carrier_id'], (int) $row['shipping_fee_minor'], (int) $row['shipping_rule_fee_minor'], (bool) $row['shipping_fee_pending']])
            ->toBe([$fast, 10_00, 10_00, false])
            ->and(json_decode((string) $row['address'], true)['regionId'] ?? null)->toBe($region);
    });

    it('keeps the order\'s own fee when staff choose to, with any carrier of the store', function () {
        Cx::serve('sa', 30_00);
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $region = shipCheckoutRegion();
        $fast = shipCheckoutCarrier('Fast', 10_00, [$region]);
        $order = shipCheckoutPlaced($address);

        app(EditOrderHandler::class)->handle(new EditOrder($order, 'Keep it', shipCheckoutLines($order), shipCheckoutAddress($customer, $region), carrierId: $fast, shippingFee: 'KEEP'));
        $row = shipCheckoutRow($order);

        expect([$row['ordered_carrier_id'], (int) $row['shipping_fee_minor']])->toBe([$fast, 30_00]);
    });

    it('makes the fee to be set at a place no carrier serves, and refuses keeping a fee there is none of', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $order = shipCheckoutPlaced($address);
        $unserved = shipCheckoutAddress($customer, shipCheckoutRegion());
        $carrier = Cx::serve()['carrier'];

        // Today's fee where no carrier serves: there is no carrier to choose.
        expect(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Moved', shipCheckoutLines($order), $unserved, carrierId: $carrier)))
            ->toThrow(InvalidSalesAttribute::class);

        app(EditOrderHandler::class)->handle(new EditOrder($order, 'Moved', shipCheckoutLines($order), $unserved));
        $row = shipCheckoutRow($order);

        expect([(bool) $row['shipping_fee_pending'], $row['ordered_carrier_id'], (int) $row['shipping_fee_minor']])->toBe([true, null, 0])
            ->and(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Back', shipCheckoutLines($order), $address, shippingFee: 'KEEP')))
            ->toThrow(InvalidSalesAttribute::class);
    });

    it('refuses a carrier that does not serve the new place for today\'s fee, and a choice with no new place', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $order = shipCheckoutPlaced($address);
        $region = shipCheckoutRegion();
        shipCheckoutCarrier('Fast', 10_00, [$region]);
        $moved = shipCheckoutAddress($customer, $region);
        $notThere = Cx::serve()['carrier'];

        expect(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Moved', shipCheckoutLines($order), $moved, carrierId: $notThere)))
            ->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Same place', shipCheckoutLines($order), null, shippingFee: 'TODAY')))
            ->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Odd', shipCheckoutLines($order), $moved, shippingFee: 'LATER')))
            ->toThrow(InvalidSalesAttribute::class);
    });
});

describe('a staff address change, refused (gap 6.1)', function () {
    it('refuses keeping the fee with a carrier that is not the store\'s, and an address in another store\'s country', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $order = shipCheckoutPlaced($address);
        $region = shipCheckoutRegion();
        $moved = shipCheckoutAddress($customer, $region);
        $doha = Cx::asSuperAdmin(static function (): string {
            app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));

            return app(AddPlaceHandler::class)->handle(new AddPlace('QA', PlaceKind::Region->value, null, 'الدوحة', 'Doha'));
        });
        $qatar = shipCheckoutAddress($customer, $doha, 'QA');
        Cx::asSuperAdmin(static fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('ae', 'QA')));
        $before = shipCheckoutRow($order);

        expect(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Keep', shipCheckoutLines($order), $moved, carrierId: strtolower((string) Str::ulid()), shippingFee: 'KEEP')))
            ->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Qatar', shipCheckoutLines($order), $qatar)))
            ->toThrow(AddressInOtherStore::class)
            ->and(shipCheckoutRow($order)['address'])->toBe($before['address']);
    });
});

describe('what the customer and Ops see (gap 6.7)', function () {
    it('names the carrier ordered with before it ships and the one shipped with after, with the Track link', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $order = shipCheckoutPlaced($address);
        $number = (string) shipCheckoutRow($order)['number'];
        $own = static function () use ($customer, $number) {
            Fx::actAsCustomer($customer);

            return app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));
        };
        $message = static fn () => app(SalesReports::class)->orderForMessage($order, 'en');

        $before = $own()->shippingDetail;
        $toldBefore = $message();
        shipCheckoutStaff();
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'Qatar Post', trackingNumber: 'Q1', trackingLink: 'https://post.example/Q1'));
        $after = $own()->shippingDetail;
        $toldAfter = $message();

        expect([$before?->carrier('en'), $before?->carrier('ar'), $before?->trackingLink])->toBe([Cx::CARRIER, 'ناقل الاختبار', null])
            ->and([$after?->carrier('en'), $after?->trackingLink])->toBe(['Qatar Post', 'https://post.example/Q1'])
            ->and([$toldBefore?->carrier, $toldBefore?->trackingLink, $toldBefore?->shippingFeePending])->toBe([Cx::CARRIER, null, false])
            ->and([$toldAfter?->carrier, $toldAfter?->trackingLink])->toBe(['Qatar Post', 'https://post.example/Q1']);
    });
});

describe('the box suggestion (gap 5.1)', function () {
    it('shows none while the store has no box, and Shipping\'s answer on the order page and the slip once it has', function () {
        ['address' => $address] = Cx::ready();
        $order = shipCheckoutPlaced($address);
        $none = app(ViewOrderHandler::class)->handle(new ViewOrder($order))->box;

        Cx::asSuperAdmin(static fn () => app(CreateBoxHandler::class)->handle(new CreateBox(Fx::storeId('sa'), 'Big box', 1200, 800, 800, 30_000)));
        shipCheckoutStaff();
        $shown = app(ViewOrderHandler::class)->handle(new ViewOrder($order))->box;
        $slip = app(PrintOrderHandler::class)->handle(new PrintOrder($order))->box;

        // The test sizes carry no weight or size: Shipping says pack by hand, and why.
        expect($none)->toBeNull()
            ->and([$shown?->byHand, $shown?->reason?->value])->toBe([true, 'NO_SIZE_FACTS'])
            ->and($slip)->toEqual($shown);
    });
});

describe('the table (§5)', function () {
    it('refuses a fee to be set with a fee, a link that is not https, and an ordered carrier without its names', function (array $change) {
        ['address' => $address] = Cx::ready();
        $order = shipCheckoutPlaced($address);

        expect(fn () => DB::table('sales.orders')->where('id', $order)->update($change))->toThrow(QueryException::class);
    })->with([
        'pending with a fee' => [['shipping_fee_pending' => true, 'shipping_fee_minor' => 1, 'ordered_carrier_id' => null, 'ordered_carrier_ar' => null, 'ordered_carrier_en' => null]],
        'an ordered carrier without names' => [['ordered_carrier_ar' => null]],
        'a free kind without an amount' => [['shipping_free_kind' => 'STORE', 'shipping_rule_free_above_minor' => null]],
        'a link that is not https' => [['shipment_status' => 'SHIPPED', 'shipped_at' => now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA', 'tracking_link' => 'http://track.example/1']],
        'a carrier with one name only' => [['shipment_status' => 'SHIPPED', 'shipped_at' => now(), 'carrier_en' => 'SMSA', 'carrier_ar' => null]],
        'a carrier id with no name' => [['carrier_id' => '01J9CARRIER0000000000000AA']],
    ]);
});
