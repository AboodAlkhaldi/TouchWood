<?php

declare(strict_types=1);

namespace Tests\Modules\Sales\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrder;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrderHandler;
use Modules\Sales\Application\Command\StartCheckout\StartCheckout;
use Modules\Sales\Application\Command\StartCheckout\StartCheckoutHandler;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Domain\Model\PlacedOrder;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

/**
 * A checkout ready to quote and place (sales.md §1.2, §1.3): sizes on sale in the 'sa' store with stock,
 * a customer who may order with an address there, their cart filled; and the use cases as that customer.
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
        $address = Bx::savedAddress($customer, $storeCode);
        Fx::actAsCustomer($customer);
        self::add($variants[0], 2, storeCode: $storeCode);
        self::add($variants[1], 1, storeCode: $storeCode);

        return ['customer' => $customer, 'address' => $address, 'variants' => $variants, 'product' => $product];
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
