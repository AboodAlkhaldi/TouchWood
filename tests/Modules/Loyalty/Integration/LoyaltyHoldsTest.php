<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPoints;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\DeductionTooLarge;
use Modules\Loyalty\Domain\Exception\PointsHeld;
use Modules\Loyalty\Domain\Exception\RedemptionChanged;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Dto\ReservePoints;
use Modules\Loyalty\Public\Dto\SettlePointsHold;
use Modules\Loyalty\Public\Dto\SettleReturn;
use Modules\Loyalty\Public\Enums\Audience;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    CarbonImmutable::setTestNow('2026-06-01 12:00:00 UTC');
    Lx::programme();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/*
| Points held while their customer pays online (loyalty.md §9.6; stage 7 step 7, with the owner's answers
| of 2026-10-29: held points are untouchable (gap 7.2), a hold writes nothing in the history (7.3), a
| confirm is never checked again (7.4)). Every helper is named after this file's subject.
*/

/** Points given by an admin, as a lot that lives the store's period. */
function loyaltyHoldsGive(string $customerId, int $points): void
{
    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    app(AddPointsHandler::class)->handle(new AddPoints($customerId, Fx::storeId('sa'), $points, 'Goodwill'));
}

/** Holds what the quote says for a try, as Pay will; answers the try's id. */
function loyaltyHoldsReserve(string $customerId, RedemptionQuote $quote, int $netMinor, ?string $orderId = null): string
{
    $orderId ??= Lx::orderId();
    Fx::actAsCustomer($customerId);
    Lx::api()->reserveRedemption(new ReservePoints($orderId, $customerId, Fx::storeId('sa'), $quote->pointsCharged, $quote->discount, Audience::Public, Lx::money($netMinor), Lx::placing()));

    return $orderId;
}

function loyaltyHoldsConfirm(string $orderId): void
{
    Lx::api()->confirmRedemption(new SettlePointsHold(Fx::storeId('sa'), $orderId, 'SA-'.substr($orderId, -6), Lx::placing()));
}

function loyaltyHoldsRelease(string $orderId): void
{
    Lx::api()->releaseRedemption(new SettlePointsHold(Fx::storeId('sa'), $orderId, null, Lx::placing()));
}

function loyaltyHoldsBalance(string $customerId): int
{
    return (int) DB::table('loyalty.accounts')->where('customer_id', $customerId)->value('balance');
}

it('holds the quoted points, shown as held, with nothing in the history (gap 7.3)', function () {
    $customer = Fx::customer();
    loyaltyHoldsGive($customer, 500);
    $quote = Lx::quote($customer, 200, 1000_00);

    $try = loyaltyHoldsReserve($customer, $quote, 1000_00);
    loyaltyHoldsReserve($customer, $quote, 1000_00, $try);
    $balance = Lx::api()->balance($customer, Fx::storeId('sa'));

    expect([$balance->points, $balance->heldPoints])->toBe([500, 200])
        ->and(DB::table('loyalty.redemption_holds')->count())->toBe(1)
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(0)
        ->and(loyaltyHoldsBalance($customer))->toBe(500)
        // Only the free 300 can be quoted now.
        ->and(Lx::quote($customer, null, 1000_00)->mostUsable)->toBeLessThanOrEqual(300);
});

it('makes the held points the order\'s redemption when confirmed - once', function () {
    $customer = Fx::customer();
    loyaltyHoldsGive($customer, 500);
    $try = loyaltyHoldsReserve($customer, Lx::quote($customer, 200, 1000_00), 1000_00);

    loyaltyHoldsConfirm($try);
    loyaltyHoldsConfirm($try);

    expect(loyaltyHoldsBalance($customer))->toBe(300)
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->where('order_id', $try)->count())->toBe(1)
        ->and((int) DB::table('loyalty.orders')->where('order_id', $try)->value('points_redeemed'))->toBe(200)
        ->and(DB::table('loyalty.redemption_holds')->count())->toBe(0)
        ->and(Lx::api()->balance($customer, Fx::storeId('sa'))->heldPoints)->toBe(0);
});

it('frees the points on release, with nothing recorded, once; a confirm after it does nothing', function () {
    $customer = Fx::customer();
    loyaltyHoldsGive($customer, 500);
    $try = loyaltyHoldsReserve($customer, Lx::quote($customer, 200, 1000_00), 1000_00);

    loyaltyHoldsRelease($try);
    loyaltyHoldsRelease($try);
    loyaltyHoldsConfirm($try);

    expect(loyaltyHoldsBalance($customer))->toBe(500)
        ->and(DB::table('loyalty.entries')->where('order_id', $try)->exists())->toBeFalse()
        ->and(DB::table('loyalty.orders')->where('order_id', $try)->exists())->toBeFalse();
});

it('refuses a deduction by hand into held points, naming no number (gap 7.2)', function () {
    $customer = Fx::customer();
    loyaltyHoldsGive($customer, 300);
    loyaltyHoldsReserve($customer, Lx::quote($customer, 200, 1000_00), 1000_00);
    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    $deduct = static fn (int $points) => app(DeductPointsHandler::class)->handle(new DeductPoints($customer, Fx::storeId('sa'), $points, 'A mistake'));

    expect(fn () => $deduct(250))->toThrow(PointsHeld::class);

    $deduct(100);

    expect(loyaltyHoldsBalance($customer))->toBe(200);
});

it('never lets a second try spend points the first holds', function () {
    $customer = Fx::customer();
    loyaltyHoldsGive($customer, 300);
    $quote = Lx::quote($customer, 200, 1000_00);
    loyaltyHoldsReserve($customer, $quote, 1000_00);

    // The second Pay was quoted before the first hold: refused as changed, never on held points.
    expect(fn () => loyaltyHoldsReserve($customer, $quote, 1000_00))->toThrow(RedemptionChanged::class)
        ->and(fn () => Lx::place($customer, $quote, 1000_00))->toThrow(RedemptionChanged::class);
});

it('never covers a returned order\'s earning with held points (gap 7.2)', function () {
    $customer = Fx::customer();
    // 300 earned on order X (1 point a riyal), then 200 by hand; order Y spends the 300 of X's lot first.
    $x = Lx::deliver($customer, 300_00);
    loyaltyHoldsGive($customer, 200);
    Lx::place($customer, Lx::quote($customer, 300, 1000_00), 1000_00);
    $try = loyaltyHoldsReserve($customer, Lx::quote($customer, 200, 1000_00), 1000_00);

    // X returned whole: its 300 are out with Y, and the only other points are held - nothing covers them.
    Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->orderReturned(new SettleReturn($x, Fx::storeId('sa'), Lx::orderId(), Lx::money(300_00), Lx::money(0), Lx::delivering()))));
    loyaltyHoldsConfirm($try);

    expect(loyaltyHoldsBalance($customer))->toBe(0)
        ->and(DB::table('loyalty.entries')->where('order_id', $try)->where('kind', 'REDEEMED')->value('points'))->toBe(-200);
});

it('expires a dead lot\'s points but those a hold keeps, and spends or deducts only free points (the review)', function () {
    $customer = Fx::customer();
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    loyaltyHoldsGive($customer, 200);
    CarbonImmutable::setTestNow('2026-06-15 12:00:00 UTC');
    loyaltyHoldsGive($customer, 100);
    // Held a minute before the first lot's date: the hold keeps 50 of it.
    CarbonImmutable::setTestNow('2026-07-01 11:59:00 UTC');
    $try = loyaltyHoldsReserve($customer, Lx::quote($customer, 50, 1000_00), 1000_00);
    CarbonImmutable::setTestNow('2026-07-01 12:01:00 UTC');
    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    $deduct = static fn (int $points) => app(DeductPointsHandler::class)->handle(new DeductPoints($customer, Fx::storeId('sa'), $points, 'A mistake'));

    // The first lot's other 150 expired; 100 live and free, 50 kept.
    expect(fn () => $deduct(200))->toThrow(DeductionTooLarge::class)
        ->and(Lx::quote($customer, null, 1000_00)->mostUsable)->toBeLessThanOrEqual(100);

    $deduct(100);
    Fx::actAsCustomer($customer);
    loyaltyHoldsConfirm($try);

    expect(loyaltyHoldsBalance($customer))->toBe(0)
        ->and((int) DB::table('loyalty.entries')->where('kind', 'EXPIRED')->sum('points'))->toBe(-150)
        ->and(DB::table('loyalty.entries')->where('order_id', $try)->where('kind', 'REDEEMED')->value('points'))->toBe(-50);
});

it('deducts from free points only, never the lot points a hold keeps', function () {
    $customer = Fx::customer();
    loyaltyHoldsGive($customer, 100);
    CarbonImmutable::setTestNow('2026-06-15 12:00:00 UTC');
    loyaltyHoldsGive($customer, 100);
    $lots = DB::table('loyalty.lots')->orderBy('expires_at')->pluck('id')->all();
    // The hold keeps the first lot's 100, the one a redemption spends first.
    $try = loyaltyHoldsReserve($customer, Lx::quote($customer, 100, 1000_00), 1000_00);
    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    app(DeductPointsHandler::class)->handle(new DeductPoints($customer, Fx::storeId('sa'), 100, 'A mistake'));
    Fx::actAsCustomer($customer);
    loyaltyHoldsConfirm($try);

    $deducted = (int) DB::table('loyalty.entries')->where('kind', 'DEDUCTED')->value('id');
    $redeemed = (int) DB::table('loyalty.entries')->where('kind', 'REDEEMED')->value('id');

    expect(DB::table('loyalty.lot_movements')->where('entry_id', $deducted)->pluck('lot_id')->all())->toBe([$lots[1]])
        ->and(DB::table('loyalty.lot_movements')->where('entry_id', $redeemed)->pluck('lot_id')->all())->toBe([$lots[0]]);
});

it('keeps held points from expiring until the hold ends; expired at the release, spent at a confirm', function () {
    $released = Fx::customer();
    $confirmed = Fx::customer('omar@example.test');
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    loyaltyHoldsGive($released, 200);
    loyaltyHoldsGive($confirmed, 200);
    $first = loyaltyHoldsReserve($released, Lx::quote($released, 200, 1000_00), 1000_00);
    $second = loyaltyHoldsReserve($confirmed, Lx::quote($confirmed, 200, 1000_00), 1000_00);

    // A month and a minute later the lots' date has passed while they are held.
    CarbonImmutable::setTestNow('2026-07-01 12:01:00 UTC');
    loyaltyHoldsConfirm($second);
    loyaltyHoldsRelease($first);

    expect(loyaltyHoldsBalance($confirmed))->toBe(0)
        ->and(DB::table('loyalty.entries')->where('order_id', $second)->where('kind', 'REDEEMED')->count())->toBe(1)
        ->and(loyaltyHoldsBalance($released))->toBe(0)
        ->and(DB::table('loyalty.entries')->where('kind', 'EXPIRED')->count())->toBe(1);
});
