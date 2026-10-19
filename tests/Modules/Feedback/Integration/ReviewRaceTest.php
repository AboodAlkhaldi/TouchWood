<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use Modules\Feedback\Application\Command\WriteReview\WriteReview;
use Modules\Feedback\Application\Command\WriteReview\WriteReviewHandler;
use Modules\Feedback\Application\Review\ReturnedReviews;
use Modules\Feedback\Application\Review\ReviewLock;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseReviews;
use Modules\Sales\Public\Dto\ReturnedProductsDto;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| A return completing while a review is being written still flips it (feedback.md §1.1, §8): the
| return's listener waits for the (customer, product) lock the writer holds, then reads the review it
| wrote — never missing it.
|
| Outside RefreshDatabase's transaction (lesson 87), as Promotions' race tests: a second connection
| sees only committed rows, so this test commits its own and empties the database before and after
| itself with migrate:fresh. Under --parallel it would run on the base database every process shares,
| so it is skipped.
*/

function reviewRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (reviewRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.reviews_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (reviewRaceInParallel()) {
        return;
    }

    DB::connection('reviews_race')->disconnect();
    // The store directory read during the test is cached: a migration that gives every store an
    // address format must not see the stores the wipe removes.
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('flips a review a return completed against while it was being written', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $sara = Fx::customer();
    Fb::delivered($sara, $product, $sixty);
    $returnId = strtolower((string) Str::ulid());
    Fb::sales()->complete($returnId, new ReturnedProductsDto($sara, Fx::storeId('sa'), [$product]));

    $first = DB::connection();
    $second = DB::connection('reviews_race');
    // The return's listener, on its own connection.
    $listener = new ReturnedReviews(Fb::sales(), new DatabaseReviews($second), new ReviewLock($second), $second);
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        // The review is written, its lock still held: its transaction has not ended.
        Fx::actAsCustomer($sara);
        $reviewId = app(WriteReviewHandler::class)->handle(new WriteReview(Fx::storeId('sa'), $product, 5, 'Quiet hinges', 'en'));

        // The return settles meanwhile: it stops on the review's lock and gives up (55P03).
        $waited = null;

        try {
            $listener->flip($returnId);
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        expect($waited?->getCode())->toBe('55P03')
            ->and((string) $waited?->getSql())->toContain('pg_advisory_xact_lock');

        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    // Once it may, it reads the review written meanwhile and flips it.
    $listener->flip($returnId);

    expect(DB::table('feedback.reviews')->where('id', $reviewId)->value('mark'))->toBe('RETURNED');
})->skip(fn (): bool => reviewRaceInParallel(), 'Empties its database: run serially.');
