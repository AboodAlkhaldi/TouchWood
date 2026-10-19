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
| The database's own refusals behind the reviews' code (feedback.md §5; handoff §5.3): every named
| CHECK and key of the review tables, each row breaking that one rule only (lesson 112).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

it('takes every valid shape of review, and a rating', function () {
    ['product' => $product, 'variants' => [$sixty, $eighty]] = Fb::sold();
    $staff = Fx::staff();
    Fb::reviewRow($product, $sixty, Fx::customer());
    Fb::reviewRow($product, $eighty, Fx::customer('omar@example.test'), ['status' => 'REJECTED', 'reason' => 'Off topic', 'moderated_by' => $staff, 'moderated_at' => CarbonImmutable::now(), 'mark' => 'RETURNED', 'locale' => 'ar', 'rating' => 1]);
    Fb::reviewRow($product, $eighty, Fx::customer('lina@example.test'), ['status' => 'APPROVED', 'moderated_by' => $staff, 'moderated_at' => CarbonImmutable::now(), 'rating' => 5]);
    DB::table('feedback.product_ratings')->insert(['product_id' => $product, 'rating_sum' => 5, 'rating_count' => 1, 'updated_at' => CarbonImmutable::now()]);

    expect(DB::table('feedback.reviews')->count())->toBe(3);
});

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $customer = Fx::customer();

    expect(fn () => DB::transaction(function () use ($write, $product, $sixty, $customer): bool {
        $write($product, $sixty, $customer);

        return true;
    }))->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'no stars' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['rating' => 0]), 'reviews_rating'],
    'six stars' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['rating' => 6]), 'reviews_rating'],
    'no text' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['body' => '']), 'reviews_body'],
    'too much text' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['body' => str_repeat('a', 2001)]), 'reviews_body'],
    'another language' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['locale' => 'fr']), 'reviews_locale'],
    'an unknown status' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['status' => 'HIDDEN', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]), 'reviews_status'],
    'an unknown mark' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['mark' => 'GIFT']), 'reviews_mark'],
    'a moderator without a time' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['moderated_by' => Fx::staff()]), 'reviews_moderation'],
    'a reason on a shown review' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['status' => 'APPROVED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(), 'reason' => 'Why']), 'reviews_reason'],
    'an empty reason' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['status' => 'REJECTED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(), 'reason' => '']), 'reviews_reason'],
    'a store that is nothing' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['store_id' => strtolower((string) Str::ulid())]), 'reviews_store'],
    'a product that is nothing' => [fn ($p, $v, $c) => Fb::reviewRow(strtolower((string) Str::ulid()), $v, $c), 'reviews_product'],
    'a moderator who is nobody' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['status' => 'APPROVED', 'moderated_by' => strtolower((string) Str::ulid()), 'moderated_at' => CarbonImmutable::now()]), 'reviews_moderated_by'],
    'approved without moderation' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['status' => 'APPROVED']), 'reviews_pending'],
    'waiting yet moderated' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]), 'reviews_pending'],
    'a reason while waiting' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['reason' => 'Why']), 'reviews_reason'],
    'a reason too long' => [fn ($p, $v, $c) => Fb::reviewRow($p, $v, $c, ['status' => 'REJECTED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(), 'reason' => str_repeat('a', 501)]), 'reviews_reason'],
    'a second review of the product' => [function ($p, $v, $c) {
        Fb::reviewRow($p, $v, $c);
        Fb::reviewRow($p, $v, $c);
    }, 'reviews_one_per_product'],
    'a variant that is nothing' => [fn ($p, $v, $c) => Fb::reviewRow($p, strtolower((string) Str::ulid()), $c), 'reviews_variant'],
    'a customer who is nobody' => [fn ($p, $v) => Fb::reviewRow($p, $v, strtolower((string) Str::ulid())), 'reviews_customer'],
    'a rating below its count' => [fn ($p) => DB::table('feedback.product_ratings')->insert(['product_id' => $p, 'rating_sum' => 1, 'rating_count' => 2, 'updated_at' => CarbonImmutable::now()]), 'product_ratings_sum'],
    'a rating above five each' => [fn ($p) => DB::table('feedback.product_ratings')->insert(['product_id' => $p, 'rating_sum' => 11, 'rating_count' => 2, 'updated_at' => CarbonImmutable::now()]), 'product_ratings_sum'],
    'a count below nothing' => [fn ($p) => DB::table('feedback.product_ratings')->insert(['product_id' => $p, 'rating_sum' => 0, 'rating_count' => -1, 'updated_at' => CarbonImmutable::now()]), 'product_ratings_sum'],
]);
