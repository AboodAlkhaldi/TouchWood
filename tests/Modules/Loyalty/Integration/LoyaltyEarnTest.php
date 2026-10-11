<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Public\Dto\EarnPoints;
use Modules\Platform\Application\Query\StoreDirectory;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Earning on delivery (loyalty.md §1.6, §8 Earning): goods_total at the store's rate, on its own
| exponent, read at delivery; the delivery recorded even when it earns nothing; once per order.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Lx::standInForSales();
    CarbonImmutable::setTestNow('2026-06-01 12:00:00 UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{points_earned: int, goods_total_minor: int|null, delivered: bool}
 */
function loyaltyEarnOrder(string $orderId): array
{
    $row = DB::table('loyalty.orders')->where('order_id', $orderId)->first() ?? throw new RuntimeException('The order was not recorded.');

    return ['points_earned' => (int) $row->points_earned, 'goods_total_minor' => $row->goods_total_minor === null ? null : (int) $row->goods_total_minor, 'delivered' => $row->delivered_at !== null];
}

function loyaltyEarnBalance(string $customerId, string $storeCode = 'sa'): int
{
    return Lx::api()->balance($customerId, Fx::storeId($storeCode))->points;
}

it('earns on the whole units of goods_total, in a lot that lives the period and is usable at once', function () {
    Lx::programme();
    $customer = Fx::customer();

    // 1,234.56 SAR at 1 point a SAR: 1,234 points (§8).
    $order = Lx::deliver($customer, 123_456);

    $lot = DB::table('loyalty.lots')->where('order_id', $order)->first() ?? throw new RuntimeException('No lot.');

    expect(loyaltyEarnOrder($order))->toBe(['points_earned' => 1_234, 'goods_total_minor' => 123_456, 'delivered' => true])
        ->and((int) $lot->points)->toBe(1_234)
        ->and(CarbonImmutable::parse((string) $lot->expires_at)->toIso8601String())->toBe('2027-06-01T12:00:00+00:00')
        ->and(DB::table('loyalty.entries')->where('order_id', $order)->where('kind', 'EARNED')->value('points'))->toBe(1_234)
        // Dated the moment of the delivery, as the history will show it.
        ->and(CarbonImmutable::parse((string) DB::table('loyalty.entries')->where('order_id', $order)->value('occurred_at'))->toIso8601String())->toBe('2026-06-01T12:00:00+00:00')
        ->and(loyaltyEarnBalance($customer))->toBe(1_234)
        // Usable at once (owner, 2026-10-07).
        ->and(Lx::quote($customer, 100, 100_000)->pointsCharged)->toBe(100);
});

it('records a delivery that earns nothing, so asking again never earns later', function () {
    Lx::programme();
    $customer = Fx::customer();

    // 0.99 SAR earns nothing — but the delivery is recorded, and an account made.
    $order = Lx::deliver($customer, 99);

    expect(loyaltyEarnOrder($order))->toBe(['points_earned' => 0, 'goods_total_minor' => 99, 'delivered' => true])
        ->and(DB::table('loyalty.entries')->count())->toBe(0)
        ->and(DB::table('loyalty.lots')->count())->toBe(0)
        ->and(DB::table('loyalty.accounts')->where('customer_id', $customer)->count())->toBe(1);
});

it('reads whether the programme is on at delivery: delivered while off, then retried after it is on, still nothing', function () {
    $customer = Fx::customer();
    $order = Lx::deliver($customer, 50_000);

    Lx::programme();
    Lx::deliver($customer, 50_000, orderId: $order);

    expect(loyaltyEarnOrder($order)['points_earned'])->toBe(0)
        ->and(loyaltyEarnBalance($customer))->toBe(0);
});

it('earns once for an order delivered twice', function () {
    Lx::programme();
    $customer = Fx::customer();
    $order = Lx::deliver($customer, 10_000);

    Lx::deliver($customer, 10_000, orderId: $order);

    expect(DB::table('loyalty.entries')->where('order_id', $order)->count())->toBe(1)
        ->and(loyaltyEarnBalance($customer))->toBe(100);
});

it('earns at the store\'s rate and its period, as they read at delivery', function () {
    Lx::programme(settings: [ProgrammeSettings::EARN_PER_UNIT => 5, ProgrammeSettings::EXPIRY_MONTHS => 1]);
    $customer = Fx::customer();

    $order = Lx::deliver($customer, 123_456);

    expect(loyaltyEarnOrder($order)['points_earned'])->toBe(6_172)
        ->and(CarbonImmutable::parse((string) DB::table('loyalty.lots')->where('order_id', $order)->value('expires_at'))->toIso8601String())
        ->toBe('2026-07-01T12:00:00+00:00');
});

it('earns on a 3-decimal currency\'s own exponent', function () {
    // A store moved to a 3-decimal currency before anything was sold in it.
    DB::table('platform.currencies')->insert(['code' => 'KWD', 'exponent' => 3, 'name' => json_encode(['ar' => 'دينار كويتي', 'en' => 'Kuwaiti Dinar']), 'abbreviation' => json_encode(['ar' => 'د.ك', 'en' => 'KWD']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('platform.stores')->where('code', 'eg')->update(['currency_code' => 'KWD']);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    Lx::programme('eg');
    $customer = Fx::customer(storeCode: 'eg');

    // 123.456 at 1 a unit: 123 points; read at 2 decimals it would be 1,234.
    $order = Lx::deliver($customer, 123_456, 'eg');

    expect(loyaltyEarnOrder($order)['points_earned'])->toBe(123);
});

it('earns for a company account as for an individual', function () {
    Lx::programme();
    $company = Fx::customer('buyer@company.example.test', accountType: 'company');

    Lx::deliver($company, 10_000);

    expect(loyaltyEarnBalance($company))->toBe(100);
});

it('refuses a delivery of a cancelled order', function () {
    Lx::programme();
    $customer = Fx::customer();
    $order = Lx::deliver($customer, 99);
    // Cancelling comes with step 4: the order's record as a cancellation leaves it.
    DB::table('loyalty.orders')->where('order_id', $order)->update(['delivered_at' => null, 'goods_total_minor' => null, 'cancelled_at' => now()]);

    expect(fn () => Lx::deliver($customer, 10_000, orderId: $order))->toThrow(OrderNotSettleable::class)
        ->and(DB::table('loyalty.entries')->count())->toBe(0);
});

it('refuses what Sales could never mean, before anything is written', function (Closure $earn, string $attribute) {
    Lx::programme();
    $customer = Fx::customer();
    $other = Fx::customer('other@example.test');
    $order = Lx::deliver($customer, 99);

    try {
        Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->earn($earn($customer, $other, $order))));
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe($attribute)
            ->and(DB::table('loyalty.entries')->count())->toBe(0);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
})->with([
    'a store other than the order\'s recorded store' => [fn (string $c, string $o, string $order) => new EarnPoints($order, 'T-1', $c, Fx::storeId('eg'), Lx::money(10_000, 'eg'), Lx::delivering()), 'store'],
    'another customer\'s order' => [fn (string $c, string $o, string $order) => new EarnPoints($order, 'T-1', $o, Fx::storeId('sa'), Lx::money(10_000), Lx::delivering()), 'customer'],
    'another currency' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), 'T-1', $c, Fx::storeId('sa'), Money::of(10_000, 'EGP'), Lx::delivering()), 'currency'],
    'a goods total below 0' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), 'T-1', $c, Fx::storeId('sa'), Lx::money(-1), Lx::delivering()), 'amount'],
    'no such customer' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), 'T-1', Lx::orderId(), Fx::storeId('sa'), Lx::money(10_000), Lx::delivering()), 'customer'],
    'an order id that is not one' => [fn (string $c, string $o, string $order) => new EarnPoints('order-1', 'T-1', $c, Fx::storeId('sa'), Lx::money(10_000), Lx::delivering()), 'order'],
    'an empty order number' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), '', $c, Fx::storeId('sa'), Lx::money(10_000), Lx::delivering()), 'order_number'],
    'an order number past 40 characters' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), str_repeat('9', 41), $c, Fx::storeId('sa'), Lx::money(10_000), Lx::delivering()), 'order_number'],
    'a store id that is not one' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), 'T-1', $c, 'sa', Lx::money(10_000), Lx::delivering()), 'store'],
    'no such store' => [fn (string $c, string $o, string $order) => new EarnPoints(Lx::orderId(), 'T-1', $c, Lx::orderId(), Lx::money(10_000), Lx::delivering()), 'store'],
]);

it('refuses a goods total below 0 itself, before the arithmetic would', function () {
    // The programme off: nothing is worked out, so only the check of what Sales passed can refuse.
    $customer = Fx::customer();

    try {
        Lx::deliver($customer, -1);
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe('amount')
            ->and(DB::table('loyalty.orders')->count())->toBe(0);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
});

it('reads ids however they are written, and takes an order number of 40 characters', function () {
    Lx::programme();
    $customer = Fx::customer();
    $order = Lx::orderId();
    $number = str_repeat('9', 40);
    $earn = static fn (string $orderId, string $customerId): EarnPoints => new EarnPoints($orderId, $number, $customerId, strtoupper(Fx::storeId('sa')), Lx::money(10_000), Lx::delivering());

    Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->earn($earn(strtoupper($order), strtoupper($customer)))));
    Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->earn($earn($order, $customer))));

    expect(DB::table('loyalty.orders')->where('order_id', $order)->value('order_number'))->toBe($number)
        ->and(DB::table('loyalty.entries')->where('order_id', $order)->count())->toBe(1)
        ->and(loyaltyEarnBalance($customer))->toBe(100);
});
