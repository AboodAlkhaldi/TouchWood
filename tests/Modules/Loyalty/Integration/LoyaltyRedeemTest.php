<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Exception\PointsProgrammeOff;
use Modules\Loyalty\Domain\Exception\RedemptionChanged;
use Modules\Loyalty\Domain\Exception\RedemptionRefused;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsLedger;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Enums\Audience;
use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Redeeming at checkout (loyalty.md §1.7, §8 Redeeming): the quote, which changes nothing; placing
| it, worked out again under the account's lock; the lots expiring first; once per order.
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

/** A customer with points earned on delivered orders, one lot each. */
function loyaltyRedeemCustomer(int ...$goodsMinor): string
{
    $customer = Fx::customer();

    foreach ($goodsMinor as $goods) {
        Lx::deliver($customer, $goods);
    }

    return $customer;
}

/**
 * The rows of each ledger table, to prove a read wrote nothing.
 *
 * @return array<string, int>
 */
function loyaltyRedeemRows(): array
{
    return array_map(
        static fn (string $table): int => DB::table("loyalty.{$table}")->count(),
        ['accounts' => 'accounts', 'orders' => 'orders', 'entries' => 'entries', 'lots' => 'lots', 'takes' => 'takes', 'lot_movements' => 'lot_movements'],
    );
}

it('takes the chosen points in PARTIAL, from the lot that expires first, and records the order with its cap', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    CarbonImmutable::setTestNow('2026-07-01 12:00:00 UTC');
    Lx::deliver($customer, 50_000);

    $quote = Lx::quote($customer, 400, 100_000);
    $order = Lx::place($customer, $quote, 100_000);

    $takes = DB::table('loyalty.takes')->orderBy('id')->get()->all();
    $row = DB::table('loyalty.orders')->where('order_id', $order)->first() ?? throw new RuntimeException('Not recorded.');

    expect([$quote->mode, $quote->pointsCharged, $quote->discount->minorUnits, $quote->mostUsable, $quote->refusal])->toBe([RedemptionMode::Partial, 400, 400, 800, null])
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(400)
        // 300 from the June lot, which expires first, then 100 from July's.
        ->and(array_map(static fn (stdClass $take): int => (int) $take->points, $takes))->toBe([300, 100])
        ->and(DB::table('loyalty.entries')->where('order_id', $order)->where('kind', 'REDEEMED')->value('points'))->toBe(-400)
        ->and(CarbonImmutable::parse((string) DB::table('loyalty.entries')->where('order_id', $order)->value('occurred_at'))->toIso8601String())->toBe('2026-07-01T12:00:00+00:00')
        ->and([(int) $row->points_redeemed, (int) $row->discount_minor, (int) $row->redemption_cap_percent])->toBe([400, 400, 50]);
});

it('takes the whole balance in ALL_OR_NOTHING, each audience by its own switch', function () {
    Lx::programme(settings: [ProgrammeSettings::PUBLIC_PARTIAL => false]);
    $customer = loyaltyRedeemCustomer(30_000);

    $public = Lx::quote($customer, 10, 100_000);
    $company = Lx::quote($customer, 10, 100_000, Audience::Company);

    expect([$public->mode, $public->pointsCharged])->toBe([RedemptionMode::AllOrNothing, 300])
        ->and([$company->mode, $company->pointsCharged])->toBe([RedemptionMode::Partial, 10]);

    Lx::place($customer, $public, 100_000);

    expect(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(0);
});

it('uses points up to the cap and keeps the rest, in both modes', function (bool $partial) {
    Lx::programme(settings: [ProgrammeSettings::PUBLIC_PARTIAL => $partial]);
    $customer = loyaltyRedeemCustomer(800_000);

    // 100.00 at 50%: at most 50.00, 5,000 points; 3,000 of the 8,000 stay.
    $quote = Lx::quote($customer, $partial ? 8_000 : null, 10_000);
    Lx::place($customer, $quote, 10_000);

    expect([$quote->pointsCharged, $quote->discount->minorUnits])->toBe([5_000, 5_000])
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(3_000);
})->with(['PARTIAL' => true, 'ALL_OR_NOTHING' => false]);

it('charges 3 points for 4 asked at 150 a unit', function () {
    Lx::programme(settings: [ProgrammeSettings::POINTS_PER_UNIT_OFF => 150]);
    $customer = loyaltyRedeemCustomer(10_000);

    $quote = Lx::quote($customer, 4, 100_000);
    $order = Lx::place($customer, $quote, 100_000);
    $row = DB::table('loyalty.orders')->where('order_id', $order)->first() ?? throw new RuntimeException('Not recorded.');

    // Points and discount differ at this rate, so neither can stand in for the other anywhere.
    expect([$quote->pointsCharged, $quote->discount->minorUnits])->toBe([3, 2])
        ->and([(int) $row->points_redeemed, (int) $row->discount_minor])->toBe([3, 2])
        ->and(DB::table('loyalty.entries')->where('order_id', $order)->value('points'))->toBe(-3)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(97)
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(97);
});

it('refuses 4 points placed for the 2 halalas 3 points buy: the points no longer work out', function () {
    Lx::programme(settings: [ProgrammeSettings::POINTS_PER_UNIT_OFF => 150]);
    $customer = loyaltyRedeemCustomer(10_000);

    // The discount is right; only the points differ from what the rules charge.
    expect(fn () => Lx::place($customer, new RedemptionQuote(RedemptionMode::Partial, 100, 4, Lx::money(2), null), 100_000))
        ->toThrow(RedemptionChanged::class);
});

it('takes from a lot made later that expires sooner, before an older one', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(10_000);
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    Lx::deliver($customer, 10_000);
    [$older, $sooner] = DB::table('loyalty.lots')->orderBy('id')->pluck('id')->all();

    Lx::place($customer, Lx::quote($customer, 50, 100_000), 100_000);

    // One take: the later lot expires first, and the older one is left untouched.
    expect(DB::table('loyalty.takes')->get(['lot_id', 'points'])->map(fn (stdClass $take): array => [(int) $take->lot_id, (int) $take->points])->all())
        ->toBe([[(int) $sooner, 50]])
        ->and((int) DB::table('loyalty.lots')->where('id', $older)->value('points_left'))->toBe(100);
});

it('locks the account first and then its lots in spending order, inside the placement', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(10_000, 10_000);
    $quote = Lx::quote($customer, 150, 100_000);
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'for update')) {
            $locks[] = $sql;
        }
    });

    Lx::place($customer, $quote, 100_000);

    // The account; its dead lots, swept; the order's row; then the live lots it spends.
    expect($locks)->toHaveCount(4)
        ->and($locks[0])->toContain('"loyalty"."accounts"')
        ->and($locks[1])->toContain('"loyalty"."lots"')
        ->and($locks[1])->toContain('"expires_at" <=')
        ->and($locks[2])->toContain('"loyalty"."orders"')
        ->and($locks[3])->toContain('"loyalty"."lots"')
        ->and($locks[3])->toContain('"expires_at" >')
        ->and($locks[3])->toContain('order by "expires_at" asc, "id" asc');
});

it('places for a company by the company\'s own mode', function () {
    Lx::programme(settings: [ProgrammeSettings::COMPANY_PARTIAL => false]);
    $company = Fx::customer('buyer@company.example.test', accountType: 'company');
    Lx::deliver($company, 30_000);

    // A part of the balance, as an individual may choose: a company here uses all or none.
    expect(fn () => Lx::place($company, new RedemptionQuote(RedemptionMode::Partial, 300, 100, Lx::money(100), null), 100_000, Audience::Company))
        ->toThrow(RedemptionChanged::class);

    $quote = Lx::quote($company, 10, 100_000, Audience::Company);
    Lx::place($company, $quote, 100_000, Audience::Company);

    expect([$quote->mode, $quote->pointsCharged])->toBe([RedemptionMode::AllOrNothing, 300])
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->value('points'))->toBe(-300)
        ->and(Lx::api()->balance($company, Fx::storeId('sa'))->points)->toBe(0);
});

it('refuses a placement in a store other than the order\'s recorded store', function () {
    Lx::programme();
    Lx::programme('eg');
    $customer = loyaltyRedeemCustomer(30_000);
    $quote = Lx::quote($customer, 100, 100_000);
    $order = Lx::place($customer, $quote, 100_000);
    Fx::actAsCustomer($customer);

    try {
        DB::transaction(fn () => Lx::api()->redeem(new RedeemPoints(
            $order, 'T-1', $customer, Fx::storeId('eg'), 100, Lx::money(100, 'eg'), Audience::Public, Lx::money(100_000, 'eg'), Lx::placing(),
        )));
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe('store');

        return;
    }

    throw new RuntimeException('Nothing was refused.');
});

it('records a delivery on the order that redeemed, and earns on it', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $order = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);

    Lx::deliver($customer, 49_900, orderId: $order);

    $row = DB::table('loyalty.orders')->where('order_id', $order)->first() ?? throw new RuntimeException('Not recorded.');

    expect([(int) $row->points_redeemed, (int) $row->discount_minor, (int) $row->goods_total_minor, (int) $row->points_earned])->toBe([100, 100, 49_900, 499])
        ->and($row->delivered_at)->not->toBeNull()
        ->and(DB::table('loyalty.orders')->count())->toBe(2)
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(699);
});

it('answers a refusal in the quote, and refuses placing it, charging nothing', function (array $settings, int $balanceGoods, ?int $asked, int $net, RedemptionRefusal $refusal, string $placing) {
    Lx::programme(settings: $settings);
    $customer = loyaltyRedeemCustomer(...($balanceGoods > 0 ? [$balanceGoods] : []));
    $before = loyaltyRedeemRows();

    $quote = Lx::quote($customer, $asked, $net);

    expect($quote->refusal)->toBe($refusal)
        ->and($quote->pointsCharged)->toBe(0)
        ->and($quote->discount->isZero())->toBeTrue()
        ->and(loyaltyRedeemRows())->toBe($before);

    // An ask of 0 cannot be placed at all: points placed are a positive whole number.
    if ($asked === 0) {
        return;
    }

    // Placed anyway, as the points asked: worked out again, the rules refuse it — no longer what a
    // quote showed, so checkout quotes again (owner, 2026-10-11); with no points at all, no quote
    // could have shown any.
    $placed = new RedemptionQuote($quote->mode, $quote->mostUsable, $asked ?? 1, Lx::money(1), null);

    expect(fn () => Lx::place($customer, $placed, $net))->toThrow($placing)
        ->and(loyaltyRedeemRows())->toBe($before);
})->with([
    'no points' => [[], 0, 100, 100_000, RedemptionRefusal::NothingToRedeem, RedemptionRefused::class],
    'an ask of 0' => [[], 30_000, 0, 100_000, RedemptionRefusal::OutOfRange, RedemptionChanged::class],
    'an ask above the balance' => [[], 30_000, 301, 100_000, RedemptionRefusal::OutOfRange, RedemptionChanged::class],
    'a cap of 0%' => [[ProgrammeSettings::MAX_REDEMPTION_PERCENT => 0], 30_000, 100, 100_000, RedemptionRefusal::NothingUnderCap, RedemptionChanged::class],
    'one point at 150 a unit' => [[ProgrammeSettings::POINTS_PER_UNIT_OFF => 150], 30_000, 1, 100_000, RedemptionRefusal::WorthNothing, RedemptionChanged::class],
    'below the minimum' => [[ProgrammeSettings::MINIMUM_REDEMPTION => 201], 30_000, 200, 100_000, RedemptionRefusal::BelowMinimum, RedemptionChanged::class],
]);

it('refuses a placement no quote could give: a discount of 0, with its reason', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);

    expect(fn () => Lx::place($customer, new RedemptionQuote(RedemptionMode::Partial, 300, 100, Lx::money(0), null), 100_000))
        ->toThrow(RedemptionRefused::class, 'WORTH_NOTHING')
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(0);
});

it('tells checkout to quote again when the balance was spent elsewhere or a setting changed', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $quote = Lx::quote($customer, 300, 100_000);
    // Another order spends 100 first: 300 no longer fit the balance.
    Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);

    expect(fn () => Lx::place($customer, $quote, 100_000))->toThrow(RedemptionChanged::class);

    $rest = Lx::quote($customer, 200, 100_000);
    Lx::programme(settings: [ProgrammeSettings::MINIMUM_REDEMPTION => 201]);

    expect(fn () => Lx::place($customer, $rest, 100_000))->toThrow(RedemptionChanged::class)
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(200);
});

it('answers PROGRAMME_OFF while off, and refuses placing with the programme\'s own error', function () {
    $customer = Fx::customer();
    Lx::programme();
    Lx::deliver($customer, 30_000);
    $quote = Lx::quote($customer, 100, 100_000);
    Lx::programme(settings: [ProgrammeSettings::ENABLED => false]);

    expect(Lx::quote($customer, 100, 100_000)->refusal)->toBe(RedemptionRefusal::ProgrammeOff)
        ->and(fn () => Lx::place($customer, $quote, 100_000))->toThrow(PointsProgrammeOff::class)
        // Balances stay while off (§1.5).
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(300);
});

it('refuses a placement that no longer works out as quoted: the settings changed, or the balance', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $quote = Lx::quote($customer, 200, 100_000);

    Lx::programme(settings: [ProgrammeSettings::POINTS_PER_UNIT_OFF => 200]);

    expect(fn () => Lx::place($customer, $quote, 100_000))->toThrow(RedemptionChanged::class)
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(0);

    // ALL_OR_NOTHING: the balance grew since the quote, so the whole balance is no longer the quote's.
    Lx::programme(settings: [ProgrammeSettings::POINTS_PER_UNIT_OFF => 100, ProgrammeSettings::PUBLIC_PARTIAL => false]);
    $all = Lx::quote($customer, null, 100_000);
    Lx::deliver($customer, 10_000);

    expect(fn () => Lx::place($customer, $all, 100_000))->toThrow(RedemptionChanged::class);
});

it('never counts or spends a lot whose date has just passed, even before the nightly sweep', function () {
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    $customer = loyaltyRedeemCustomer(10_000);
    CarbonImmutable::setTestNow('2026-06-15 12:00:00 UTC');
    Lx::deliver($customer, 20_000);
    // A second past the first lot's date: nobody has swept it.
    CarbonImmutable::setTestNow('2026-07-01 12:00:01 UTC');

    $quote = Lx::quote($customer, null, 100_000);

    expect($quote->mostUsable)->toBe(200)
        ->and(DB::table('loyalty.entries')->where('kind', 'EXPIRED')->count())->toBe(0);

    Lx::place($customer, $quote, 100_000);

    // The placement expired the dead lot first, under the lock, and spent only the live one.
    expect(DB::table('loyalty.entries')->where('kind', 'EXPIRED')->value('points'))->toBe(-100)
        ->and(DB::table('loyalty.takes')->count())->toBe(1)
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(0);
});

it('takes equal dates from the older lot first', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(10_000, 10_000);
    $lots = DB::table('loyalty.lots')->orderBy('id')->pluck('id')->all();

    Lx::place($customer, Lx::quote($customer, 150, 100_000), 100_000);

    expect(DB::table('loyalty.takes')->orderBy('id')->get(['lot_id', 'points'])->map(fn (stdClass $take): array => [(int) $take->lot_id, (int) $take->points])->all())
        ->toBe([[(int) $lots[0], 100], [(int) $lots[1], 50]]);
});

it('redeems once for an order placed twice', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $quote = Lx::quote($customer, 100, 100_000);
    $order = Lx::place($customer, $quote, 100_000);

    Lx::place($customer, $quote, 100_000, orderId: $order);

    expect(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(1)
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->points)->toBe(200);
});

it('changes nothing when quoting, or previewing what an order earns', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $before = loyaltyRedeemRows();

    Lx::quote($customer, 100, 100_000);
    Lx::quote(Fx::customer('nobody-yet@example.test'), null, 100_000);
    Lx::api()->earningPreview(Fx::storeId('sa'), Lx::money(123_456));
    Lx::api()->balance($customer, Fx::storeId('sa'));

    expect(loyaltyRedeemRows())->toBe($before)
        ->and(Lx::api()->earningPreview(Fx::storeId('sa'), Lx::money(123_456)))->toBe(1_234);
});

it('previews 0 while the programme is off', function () {
    expect(Lx::api()->earningPreview(Fx::storeId('sa'), Lx::money(123_456)))->toBe(0);
});

it('answers the balance, what it is worth, and the points that expire next', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(10_000, 20_000);
    CarbonImmutable::setTestNow('2026-08-01 12:00:00 UTC');
    Lx::deliver($customer, 5_000);

    $balance = Lx::api()->balance($customer, Fx::storeId('sa'));

    expect($balance->points)->toBe(350)
        ->and($balance->worth->minorUnits)->toBe(350)
        ->and($balance->worth->currencyCode)->toBe('SAR')
        // The two June lots expire together, a year on.
        ->and($balance->nextExpiry?->format(DATE_ATOM))->toBe('2027-06-01T12:00:00+00:00')
        ->and($balance->nextExpiryPoints)->toBe(300)
        ->and(Lx::api()->balance(Fx::customer('none@example.test'), Fx::storeId('sa'))->nextExpiry)->toBeNull();
});

// RefreshDatabase holds a transaction through every test, so a connection with none is given
// directly (lesson 87): rows written outside a transaction would commit one by one.
it('refuses to write points outside a transaction', function () {
    $db = new class(static fn (): PDO => throw new LogicException('No database behind this connection.')) extends Connection
    {
        public function transactionLevel(): int
        {
            return 0;
        }
    };
    $ledger = new DatabasePointsLedger($db);
    $account = new PointsAccount(Lx::orderId(), Lx::orderId(), StoreId::fromString(Fx::storeId('sa')), 100);
    $now = CarbonImmutable::now();

    expect(fn () => $ledger->redeem($account, Lx::orderId(), 'T-1', 'SAR', 10, 10, 50, $now))->toThrow(LogicException::class, 'inside the change')
        ->and(fn () => $ledger->earn($account, Lx::orderId(), 'T-1', 'SAR', 10_000, 100, $now->addYear(), $now))->toThrow(LogicException::class, 'inside the change');
});

it('refuses a placement Sales could never mean', function (Closure $redeem, string $attribute) {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    Fx::actAsCustomer($customer);

    try {
        DB::transaction(fn () => Lx::api()->redeem($redeem($customer)));
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe($attribute)
            ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(0);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
})->with([
    'no points' => [fn (string $c) => new RedeemPoints(Lx::orderId(), 'T-1', $c, Fx::storeId('sa'), 0, Lx::money(1), Audience::Public, Lx::money(100_000), Lx::placing()), 'points'],
    'a discount in another currency' => [fn (string $c) => new RedeemPoints(Lx::orderId(), 'T-1', $c, Fx::storeId('sa'), 100, Money::of(100, 'EGP'), Audience::Public, Lx::money(100_000), Lx::placing()), 'currency'],
    'a net subtotal in another currency' => [fn (string $c) => new RedeemPoints(Lx::orderId(), 'T-1', $c, Fx::storeId('sa'), 100, Lx::money(100), Audience::Public, Money::of(100_000, 'EGP'), Lx::placing()), 'currency'],
    'a customer id that is not one' => [fn (string $c) => new RedeemPoints(Lx::orderId(), 'T-1', 'sara', Fx::storeId('sa'), 100, Lx::money(100), Audience::Public, Lx::money(100_000), Lx::placing()), 'customer'],
    'no such customer' => [fn (string $c) => new RedeemPoints(Lx::orderId(), 'T-1', Lx::orderId(), Fx::storeId('sa'), 100, Lx::money(100), Audience::Public, Lx::money(100_000), Lx::placing()), 'customer'],
]);

it('refuses an order a call for another account recorded meanwhile, taking nothing', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $quote = Lx::quote($customer, 100, 100_000);
    $before = loyaltyRedeemRows();
    Lx::racingLedger(hideUnderLock: true, recordedMeanwhile: true);

    try {
        Lx::place($customer, $quote, 100_000);
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe('customer')
            ->and(loyaltyRedeemRows())->toBe($before);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
});

it('passes over a lot already spent to nothing, to the next one', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(10_000, 10_000);
    [$first, $second] = DB::table('loyalty.lots')->orderBy('id')->pluck('id')->all();
    // The first lot, expiring first, is spent to nothing; it still expires first.
    Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);

    Lx::place($customer, Lx::quote($customer, 20, 100_000), 100_000);

    expect(DB::table('loyalty.takes')->orderBy('id')->get(['lot_id', 'points'])->map(fn (stdClass $take): array => [(int) $take->lot_id, (int) $take->points])->all())
        ->toBe([[(int) $first, 100], [(int) $second, 20]]);
});

it('answers a placement repeated once the programme is off by changing nothing, before reading what changed', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $quote = Lx::quote($customer, 100, 100_000);
    $order = Lx::place($customer, $quote, 100_000);
    Lx::programme(settings: [ProgrammeSettings::ENABLED => false]);

    Lx::place($customer, $quote, 100_000, orderId: $order);

    expect(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(1);
});

it('refuses an order placed again with other points, or for another customer, or delivered without points', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $other = Fx::customer('other@example.test');
    Lx::deliver($other, 30_000);
    $order = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
    $delivered = Lx::deliver($customer, 99);
    $before = loyaltyRedeemRows();

    $refusal = static function (Closure $place): string {
        try {
            $place();
        } catch (InvalidPointsAttribute $refused) {
            return $refused->attribute;
        }

        return 'nothing refused';
    };

    expect($refusal(fn () => Lx::place($customer, Lx::quote($customer, 50, 100_000), 100_000, orderId: $order)))->toBe('points')
        ->and($refusal(fn () => Lx::place($other, Lx::quote($other, 100, 100_000), 100_000, orderId: $order)))->toBe('customer')
        ->and(fn () => Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000, orderId: $delivered))->toThrow(OrderNotSettleable::class)
        ->and(loyaltyRedeemRows())->toBe($before);
});

it('locks an order\'s row alone, never another account\'s with it', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $order = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    DB::transaction(fn () => app(PointsLedger::class)->lockOrder($order));

    $locks = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update')));

    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toContain('"loyalty"."orders"')
        ->and($locks[0])->not->toContain('accounts');
});

it('writes nothing for an order recorded meanwhile under another account', function () {
    Lx::programme();
    $customer = loyaltyRedeemCustomer(30_000);
    $other = Fx::customer('other@example.test');
    Lx::deliver($other, 30_000);
    $order = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
    $otherAccount = app(PointsAccounts::class)->find($other, StoreId::fromString(Fx::storeId('sa'))) ?? throw new RuntimeException('No account.');
    $before = loyaltyRedeemRows();
    $now = CarbonImmutable::now();

    // As a call for the other customer would find it after waiting on the first's insert.
    [$redeemed, $earned] = DB::transaction(fn (): array => [
        app(PointsLedger::class)->redeem($otherAccount, $order, 'T-1', 'SAR', 10, 10, 50, $now),
        app(PointsLedger::class)->earn($otherAccount, $order, 'T-1', 'SAR', 10_000, 100, $now->addYear(), $now),
    ]);

    expect([$redeemed, $earned])->toBe([false, false])
        ->and(loyaltyRedeemRows())->toBe($before);
});
