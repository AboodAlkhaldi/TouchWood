<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the questions' code (feedback.md §5; handoff §5.3): every named
| CHECK and key of the questions table, each row breaking that one rule only (lesson 112).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 */
function questionTableRow(string $productId, string $customerId, array $with = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('feedback.questions')->insert([
        'id' => $id, 'product_id' => $productId, 'store_id' => Fx::storeId('sa'), 'customer_id' => $customerId,
        'body' => 'Does it fit?', 'locale' => 'en', 'status' => 'PENDING', 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$with,
    ]);

    return $id;
}

/**
 * @return array<string, mixed>
 */
function answered(): array
{
    return ['status' => 'ANSWERED', 'answer' => 'Yes.', 'answered_by' => Fx::staff(), 'answered_at' => CarbonImmutable::now()];
}

it('takes every valid shape of question', function () {
    ['product' => $product] = Fb::sold();
    $customer = Fx::customer();
    questionTableRow($product, $customer);
    questionTableRow($product, $customer, answered());
    questionTableRow($product, $customer, ['status' => 'REJECTED', 'reason' => 'Off topic']);
    questionTableRow($product, $customer, ['status' => 'REJECTED']);
    questionTableRow($product, $customer, [...answered(), 'status' => 'UNPUBLISHED', 'reason' => 'Asked to']);

    expect(DB::table('feedback.questions')->count())->toBe(5);
});

it('refuses what the code would never write', function (Closure $with, string $constraint) {
    ['product' => $product] = Fb::sold();
    $customer = Fx::customer();

    expect(fn () => DB::transaction(fn () => questionTableRow($product, $customer, $with())))->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'no text' => [fn () => ['body' => ''], 'questions_body'],
    'too much text' => [fn () => ['body' => str_repeat('a', 1001)], 'questions_body'],
    'another language' => [fn () => ['locale' => 'fr'], 'questions_locale'],
    'an unknown status' => [fn () => ['status' => 'HIDDEN'], 'questions_status'],
    'an answer without who' => [fn () => [...answered(), 'answered_by' => null], 'questions_answered'],
    'answered without an answer' => [fn () => ['status' => 'ANSWERED'], 'questions_answer_status'],
    'an answer while waiting' => [fn () => [...answered(), 'status' => 'PENDING'], 'questions_answer_status'],
    'unpublished without its answer' => [fn () => ['status' => 'UNPUBLISHED'], 'questions_answer_status'],
    'rejected with an answer' => [fn () => [...answered(), 'status' => 'REJECTED'], 'questions_answer_status'],
    'an empty answer' => [fn () => [...answered(), 'answer' => ''], 'questions_answer'],
    'an answer too long' => [fn () => [...answered(), 'answer' => str_repeat('a', 2001)], 'questions_answer'],
    'a reason while waiting' => [fn () => ['reason' => 'Why'], 'questions_reason'],
    'a reason on a shown one' => [fn () => [...answered(), 'reason' => 'Why'], 'questions_reason'],
    'a reason too long' => [fn () => ['status' => 'REJECTED', 'reason' => str_repeat('a', 501)], 'questions_reason'],
    'a store that is nothing' => [fn () => ['store_id' => strtolower((string) Str::ulid())], 'questions_store'],
    'a product that is nothing' => [fn () => ['product_id' => strtolower((string) Str::ulid())], 'questions_product'],
    'a customer who is nobody' => [fn () => ['customer_id' => strtolower((string) Str::ulid())], 'questions_customer'],
    'an answerer who is nobody' => [fn () => [...answered(), 'answered_by' => strtolower((string) Str::ulid())], 'questions_answered_by'],
]);
