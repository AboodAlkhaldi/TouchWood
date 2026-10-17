<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Promotions\Application\Command\UseCoupon\UseCouponCommand;
use Modules\Promotions\Application\Command\UseCoupon\UseCouponHandler;
use Modules\Promotions\Application\Coupon\CallerCheck;
use Modules\Promotions\Application\Coupon\CartLines;
use Modules\Promotions\Application\Coupon\Ceiling;
use Modules\Promotions\Application\Coupon\Quotes;
use Modules\Promotions\Domain\Exception\CouponRefused;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseCoupons;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Two orders at once take the last of a coupon's uses (promotions.md §2.1, §8 "Coupon uses"): one goes
| through, the other waits for the coupon's lock, asks every check again on what is left, and is
| refused — the coupon never used past its limit.
|
| The only Promotions test outside RefreshDatabase's transaction (lesson 87), as Loyalty's race test: a
| second connection sees only committed rows, so this test commits its own, and empties the database
| before and after itself with migrate:fresh — leaving it migrated and empty, as every other test
| expects. Under --parallel it would run on the base database every process shares, so it is skipped.
*/

function couponRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (couponRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.promotions_race' => config('database.connections.'.config('database.default'))]);
    Pr::standInForSales();
});

afterEach(function () {
    if (couponRaceInParallel()) {
        return;
    }

    DB::connection('promotions_race')->disconnect();
    Artisan::call('migrate:fresh');
});

it('lets one of two orders at once take the last use, and refuses the other', function () {
    $sara = Fx::customer();
    $omar = Fx::customer('omar@example.test');
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon(with: ['max_uses_total' => 1]);
    $order = static function (string $customerId) use ($a, $coupon): UseCouponCommand {
        $orderId = Pr::orderId();

        return new UseCouponCommand(new UseCoupon(
            $orderId, 'T-'.substr($orderId, -8), $coupon,
            new CouponRequest(Fx::storeId('sa'), 'SUMMER-15', $customerId, Audience::Public, Pr::prices([Pr::line($a, 200_00)])),
            Pr::money(30_00), false, Pr::placing(),
        ));
    };
    Fx::actAsCustomer($sara);

    $first = DB::connection();
    $second = DB::connection('promotions_race');
    // The second order's own repository, on its own connection.
    $secondCoupons = new DatabaseCoupons($second);
    $other = new UseCouponHandler($second, app(CallerCheck::class), $secondCoupons, new Quotes($secondCoupons, app(CartLines::class), app(Ceiling::class)));
    $secondOrder = $order($omar);
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        // The first order has taken the coupon's lock and changed nothing yet.
        app(Coupons::class)->lock(StoreId::fromString(Fx::storeId('sa')), $coupon);

        // The second, at that moment, stops on the coupon's lock itself and gives up (55P03).
        $waited = null;

        try {
            $second->transaction(fn () => $other->handle($secondOrder));
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        $sql = strtolower(str_replace('"', '', (string) $waited?->getSql()));

        expect($waited?->getCode())->toBe('55P03')
            ->and($sql)->toContain('from promotions.coupons')
            ->and($sql)->toContain('for update');

        // The first goes through, and commits.
        app(UseCouponHandler::class)->handle($order($sara));
        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    // Once it may, the second asks every check again: the coupon is used up.
    expect(Pr::thrown(fn () => $second->transaction(fn () => $other->handle($secondOrder)), CouponRefused::class)->reason)
        ->toBe(CouponRefusal::UsedUp)
        ->and(DB::table('promotions.coupon_uses')->count())->toBe(1)
        ->and((int) DB::table('promotions.coupons')->where('id', $coupon)->value('uses_total'))->toBe(1);
})->skip(fn (): bool => couponRaceInParallel(), 'Empties its database: run serially.');
