<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevel;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevelHandler;
use Modules\Promotions\Application\Command\GiftLevelFields;
use Modules\Promotions\Application\Gift\GiftLevelChecks;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseGiftLevels;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Two gift levels with one amount saved at once (promotions.md §1.7, §8 "Gifts"): the second waits for
| the store's lock, then reads the first and is refused — never two gifts for one amount.
|
| Outside RefreshDatabase's transaction (lesson 87), as the coupon race test: a second connection sees
| only committed rows, so this test commits its own and empties the database before and after itself
| with migrate:fresh. Under --parallel it would run on the base database every process shares, so it
| is skipped.
*/

function giftRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (giftRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.gifts_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (giftRaceInParallel()) {
        return;
    }

    DB::connection('gifts_race')->disconnect();
    Artisan::call('migrate:fresh');
});

it('refuses the second of two levels with one amount saved at once', function () {
    [$pen, $mug] = Px::ready(['60 cm', '80 cm'])['variants'];
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $store = Fx::storeId('sa');

    $first = DB::connection();
    $second = DB::connection('gifts_race');
    // The second save's own handler, on its own connection.
    $levels = new DatabaseGiftLevels($second);
    $other = app()->make(CreateGiftLevelHandler::class, [
        'change' => app()->make(MarketingChange::class, ['levels' => $levels, 'db' => $second]),
        'levels' => $levels,
        'checks' => app()->make(GiftLevelChecks::class, ['levels' => $levels]),
    ]);
    $secondSave = new CreateGiftLevel($store, new GiftLevelFields(1000_00, $mug, true, true));
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        // The first save holds the store's lock and has written nothing yet.
        $first->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['promotions:store:'.$store]);

        // The second, at that moment, stops on the store's lock and gives up (55P03).
        $waited = null;

        try {
            $second->transaction(fn () => $other->handle($secondSave));
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        expect($waited?->getCode())->toBe('55P03')
            ->and((string) $waited?->getSql())->toContain('pg_advisory_xact_lock');

        // The first goes through, and commits.
        app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel($store, new GiftLevelFields(1000_00, $pen, true, true)));
        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    // Once it may, the second reads the first: one amount, one gift.
    expect(fn () => $other->handle($secondSave))->toThrow(GiftLevelClash::class)
        ->and(DB::table('promotions.gift_levels')->count())->toBe(1)
        ->and(DB::table('promotions.gift_levels')->value('variant_id'))->toBe($pen);
})->skip(fn (): bool => giftRaceInParallel(), 'Empties its database: run serially.');
