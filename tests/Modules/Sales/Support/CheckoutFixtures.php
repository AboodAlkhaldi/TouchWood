<?php

declare(strict_types=1);

namespace Tests\Modules\Sales\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\RestorePlace\RestorePlace;
use Modules\Platform\Application\Command\RestorePlace\RestorePlaceHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrder;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrderHandler;
use Modules\Sales\Application\Command\StartCheckout\StartCheckout;
use Modules\Sales\Application\Command\StartCheckout\StartCheckoutHandler;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Domain\Model\PlacedOrder;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrier;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrierHandler;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroup;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroupHandler;
use Modules\Shipping\Application\Command\SetCarrierFreeAbove\SetCarrierFreeAbove;
use Modules\Shipping\Application\Command\SetCarrierFreeAbove\SetCarrierFreeAboveHandler;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

/**
 * A checkout ready to quote and place (sales.md §1.2, §1.3): sizes on sale in the 'sa' store with stock,
 * a customer who may order with an address there, their cart filled; and the use cases as that customer.
 * The store's country is served (§9.5 #2): a region of it, which saved test addresses take, priced by a carrier.
 */
final class CheckoutFixtures
{
    /** A customer of the store who may order: email and phone confirmed. */
    public static function customer(string $accountType = 'individual'): string
    {
        $customerId = Fx::customer(strtolower((string) Str::ulid()).'@example.test', 'sa', $accountType);
        DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => CarbonImmutable::now(), 'phone' => Bx::unusedPhone(), 'phone_verified_at' => CarbonImmutable::now()]);

        return $customerId;
    }

    /**
     * Two sizes on sale in the store ('sa' unless told) at 100.00 and 50.00, 100 of each in stock, a customer who may order
     * with an address there, acting as that customer, two of the first size and one of the second in the cart.
     *
     * @return array{customer: string, address: string, variants: list<string>, product: string}
     */
    public static function ready(string $accountType = 'individual', string $storeCode = 'sa'): array
    {
        ['product' => $product, 'variants' => $variants] = Px::ready(['60 cm', '80 cm']);
        Rx::switchOn($storeCode, $variants);
        Rx::set($storeCode, [$variants[0] => '100.00', $variants[1] => '50.00']);
        Ix::count($storeCode, [$variants[0] => 100, $variants[1] => 100]);
        $customer = self::customer($accountType);
        // Served at the price a test may have set before; 0 the first time.
        self::serve($storeCode, self::fee($storeCode));
        $address = Bx::savedAddress($customer, $storeCode);
        Fx::actAsCustomer($customer);
        self::add($variants[0], 2, storeCode: $storeCode);
        self::add($variants[1], 1, storeCode: $storeCode);

        return ['customer' => $customer, 'address' => $address, 'variants' => $variants, 'product' => $product];
    }

    /** The test region's English name: saved test addresses in its country take it (B2BFixtures::savedAddress). */
    public const string REGION = 'Test Region';

    /** The test carrier's English name. */
    public const string CARRIER = 'Test Carrier';

    /**
     * The store's country served: a region of it, and a carrier of the store pricing it at `$fee` (0 unless told) -
     * so a test checkout is SERVED, its order has a carrier and may ship (§9.5 #2, gap 6.2). Made once a store and
     * reused (the browser suite keeps its data); asked again with another fee, the carrier's price changes to it.
     *
     * @return array{region: string, carrier: string}
     */
    public static function serve(string $storeCode = 'sa', int $fee = 0): array
    {
        $store = Fx::storeId($storeCode);
        $country = (string) DB::table('platform.stores')->where('id', $store)->value('country_code');
        $found = DB::table('platform.places')->where('country_code', $country)->where('kind', PlaceKind::Region->value)
            ->where('name->en', self::REGION)->first(['id', 'retired']);
        $region = $found?->id;

        // Retired by `unserve` after a browser test: in use again.
        if ($found !== null && (bool) $found->retired) {
            self::asSuperAdmin(static fn () => app(RestorePlaceHandler::class)->handle(new RestorePlace((string) $found->id)));
        }
        $carrier = DB::table('shipping.carriers')->where('store_id', $store)->where('name_en', self::CARRIER)->value('id');
        $priced = $carrier !== null && DB::table('shipping.price_groups')->where('carrier_id', $carrier)->where('amount_minor', $fee)->exists();

        if ($region !== null && $priced) {
            return ['region' => (string) $region, 'carrier' => (string) $carrier];
        }

        return self::asSuperAdmin(static function () use ($store, $country, $region, $carrier, $fee): array {
            $region ??= app(AddPlaceHandler::class)->handle(new AddPlace($country, PlaceKind::Region->value, null, 'منطقة الاختبار', self::REGION));
            $carrier ??= app(CreateCarrierHandler::class)->handle(new CreateCarrier($store, $country, 'ناقل الاختبار', self::CARRIER));
            $groups = app(PriceGroupRepository::class)->ofCarrier((string) $carrier);
            $current = DB::table('shipping.price_places')->where('carrier_id', $carrier)->where('place_id', $region)->value('group_id');
            app(SavePriceGroupHandler::class)->handle(new SavePriceGroup(
                $store, (string) $carrier, $current === null ? null : (string) $current, $fee, [(string) $region], PriceGroup::versionOf($groups),
            ));

            return ['region' => (string) $region, 'carrier' => (string) $carrier];
        });
    }

    /**
     * The test region retired, so its country has no live place list again — for the browser suite, which keeps
     * its data between files: a later file typing a Saudi region would be refused (access.md amendment 76).
     */
    public static function unserve(string $storeCode = 'sa'): void
    {
        $country = DB::table('platform.stores')->where('id', Fx::storeId($storeCode))->value('country_code');
        $region = DB::table('platform.places')->where('country_code', $country)->where('kind', PlaceKind::Region->value)
            ->where('name->en', self::REGION)->where('retired', false)->value('id');

        if ($region !== null) {
            self::asSuperAdmin(static fn () => app(RetirePlaceHandler::class)->handle(new RetirePlace((string) $region)));
        }
    }

    /** The test carrier's own free-above amount (shipping.md §1.5); null: never free by it. */
    public static function carrierFreeAbove(?int $amount, string $storeCode = 'sa'): void
    {
        ['carrier' => $carrier] = self::serve($storeCode, self::fee($storeCode));
        self::asSuperAdmin(static fn () => app(SetCarrierFreeAboveHandler::class)->handle(new SetCarrierFreeAbove(Fx::storeId($storeCode), $carrier, $amount)));
    }

    /** The store-wide free-above amount, over every carrier's (shipping.md §1.5); 0: not set. */
    public static function freeForAll(int $amount, string $storeCode = 'sa'): void
    {
        self::asSuperAdmin(static fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting('shipping.free.above_all', $storeCode, $amount)));
    }

    /** The test carrier's price on the test region now. */
    private static function fee(string $storeCode): int
    {
        return (int) DB::table('shipping.price_groups AS g')->join('shipping.carriers AS c', 'c.id', '=', 'g.carrier_id')
            ->where('c.store_id', Fx::storeId($storeCode))->where('c.name_en', self::CARRIER)->value('g.amount_minor');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function asSuperAdmin(callable $work): mixed
    {
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        try {
            return $work();
        } finally {
            // As AccessFixtures::asSystem: the binding itself back, whatever it was.
            app()->scoped(ActorContext::class, $previous);
            app()->forgetScopedInstances();
        }
    }

    public static function add(string $variantId, int $quantity = 1, string $mode = 'RETAIL', string $storeCode = 'sa'): void
    {
        app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId($storeCode), $variantId, $mode, $quantity));
    }

    public static function start(string $addressId, string $way = 'STAFF_CONTACT', bool $usePoints = false, ?int $asked = null, string $storeCode = 'sa'): QuoteView
    {
        return app(StartCheckoutHandler::class)->handle(new StartCheckout(Fx::storeId($storeCode), $addressId, $usePoints, $asked, $way));
    }

    public static function place(string $quoteId, string $storeCode = 'sa'): PlacedOrder
    {
        return app(PlaceOrderHandler::class)->handle(new PlaceOrder(Fx::storeId($storeCode), $quoteId));
    }

    /**
     * A moment to pin the clock at: the next whole hour, never behind the real time — what a test made
     * before pinning stays in the past (lesson 221: a fixed date breaks the next day).
     */
    public static function clockAhead(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHour()->startOfHour();
    }

    /**
     * The customer's orders waiting for approval marked approved, so a test placing many orders stays
     * under the cap of orders waiting (sales.md §1.3, answer 51).
     */
    public static function approveWaiting(string $customerId): void
    {
        DB::table('sales.orders')->where('customer_id', $customerId)->where('status', 'PENDING_APPROVAL')
            ->update(['status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now()]);
    }

    public static function setting(string $key, int|bool $value): void
    {
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, 'sa', $value)));
    }
}
