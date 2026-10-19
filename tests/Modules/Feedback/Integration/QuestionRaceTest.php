<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestion;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestionHandler;
use Modules\Feedback\Domain\Exception\TooManyWaitingQuestions;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseQuestions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Two questions asked at once by a customer with four waiting (feedback.md §1.3, §1.4; answer 16): the
| second waits for the lock on the customer, then counts the first — never six waiting.
|
| Outside RefreshDatabase's transaction (lesson 87), as the review race test: it commits its own rows
| and empties the database before and after itself; skipped under --parallel.
*/

function questionRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (questionRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.questions_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (questionRaceInParallel()) {
        return;
    }

    DB::connection('questions_race')->disconnect();
    // The store directory read during the test is cached: the wipe must not meet the stores it removes.
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('lets one of two questions asked at once be the fifth, and refuses the other', function () {
    ['product' => $product] = Fb::sold();
    $sara = Fx::customer();
    Fx::actAsCustomer($sara);
    $ask = static fn (AskQuestionHandler $handler, string $body): string => $handler->handle(new AskQuestion(Fx::storeId('sa'), $product, $body, 'en'));

    foreach (range(1, 4) as $n) {
        $ask(app(AskQuestionHandler::class), "Question {$n}");
    }

    $first = DB::connection();
    $second = DB::connection('questions_race');
    // The second question's own handler, on its own connection.
    $other = new AskQuestionHandler(app(Authorizer::class), app(ActorContext::class), app(PlatformApi::class), app(CatalogApi::class), new DatabaseQuestions($second), $second);
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        // The fifth is asked; its lock on the customer is held until its transaction ends.
        $ask(app(AskQuestionHandler::class), 'The fifth');

        // The sixth, at that moment, stops on the customer's lock and gives up (55P03).
        $waited = null;

        try {
            $ask($other, 'The sixth');
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

    // Once it may, it counts the fifth: one too many.
    expect(fn () => $ask($other, 'The sixth'))->toThrow(TooManyWaitingQuestions::class)
        ->and(DB::table('feedback.questions')->where('customer_id', $sara)->count())->toBe(5);
})->skip(fn (): bool => questionRaceInParallel(), 'Empties its database: run serially.');
