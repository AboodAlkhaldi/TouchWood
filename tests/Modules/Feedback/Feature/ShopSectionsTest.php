<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| Feedback's sections of a product's page in the shop (feedback.md §1.1–§1.4, §2.3, §8; catalog.md
| amendment 26(b)): the rating and approved reviews, the answered questions — global, signed by the
| store that answered — and the wishlist button, ten a page with "Show more" as the same page asked
| again, in a fixed number of queries however many reviews.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function shopSectionsUrl(string $productId, string $query = ''): string
{
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $productId)->where('locale', 'en')->where('is_current', true)->value('slug');

    return "/sa/en/products/{$slug}".($query === '' ? '' : "?{$query}");
}

/**
 * The page's sections, as the browser receives them.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function shopSections(TestResponse $response): array
{
    $response->assertOk();
    /** @var array{props: array{sections: array<string, mixed>}} $page */
    $page = json_decode((string) json_encode($response->viewData('page')), true);

    return $page['props']['sections'];
}

function shopSectionsQuestion(string $productId, string $customerId, string $answeredAt, string $storeCode = 'sa', string $status = 'ANSWERED'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('feedback.questions')->insert([
        'id' => $id, 'product_id' => $productId, 'store_id' => Fx::storeId($storeCode), 'customer_id' => $customerId,
        'body' => "Asked for {$answeredAt}", 'locale' => 'en', 'status' => $status, 'answer' => 'Yes.', 'answered_by' => Fx::staff(),
        'answered_at' => $answeredAt, 'created_at' => $answeredAt, 'updated_at' => $answeredAt,
    ]);

    return $id;
}

it('gives a guest the rating, no reviews yet, nothing to write or ask, and no wishlist', function () {
    ['product' => $product] = Fb::sold();

    $sections = shopSections(get(shopSectionsUrl($product)));

    expect(array_keys($sections))->toBe(['feedback.reviews', 'feedback.questions', 'feedback.wishlist'])
        ->and($sections['feedback.reviews'])->toBe(['average' => null, 'count' => 0, 'reviews' => [], 'next' => null, 'canWrite' => false])
        ->and($sections['feedback.questions'])->toBe(['questions' => [], 'next' => null, 'canAsk' => false])
        ->and($sections['feedback.wishlist'])->toBeNull();
});

it('shows the approved reviews of every store, newest first, ten at a time, with the rating', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'eg']);
    $ids = [];

    foreach (range(1, 11) as $n) {
        $ids[] = Fb::reviewRow($product, $sixty, Fx::customer("buyer{$n}@example.test"), [
            'status' => 'APPROVED', 'rating' => 4, 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(),
            'created_at' => sprintf('2026-10-01 10:%02d:00+00', $n),
        ], $n === 3 ? 'eg' : 'sa');
    }

    Fb::reviewRow($product, $sixty, Fx::customer('waiting@example.test'), ['created_at' => '2026-10-02 10:00:00+00']);
    // The rating as Feedback keeps it: 4.25 shows 4.3, half up (amendment 25).
    DB::table('feedback.product_ratings')->insert(['product_id' => $product, 'rating_sum' => 17, 'rating_count' => 4, 'updated_at' => CarbonImmutable::now()]);

    $first = shopSections(get(shopSectionsUrl($product)))['feedback.reviews'];
    $second = shopSections(get(shopSectionsUrl($product, 'after[feedback.reviews]='.$first['next'])))['feedback.reviews'];

    expect(array_column([...$first['reviews'], ...$second['reviews']], 'reviewId'))->toBe(array_reverse($ids))
        ->and([$first['average'], $first['count'], count($first['reviews'])])->toBe([4.3, 4, 10])
        ->and($first['next'])->toBe($ids[1])
        ->and($second['next'])->toBeNull()
        ->and($first['reviews'][0]['author'])->toBe(['firstName' => 'Sara', 'lastInitial' => 'A', 'deleted' => false])
        ->and([$first['reviews'][0]['rating'], $first['reviews'][0]['mark'], $first['reviews'][0]['variant'][0]['value']])->toBe([4, 'VERIFIED_PURCHASE', '60 cm'])
        ->and(array_keys($first['reviews'][0]))->toBe(['reviewId', 'author', 'rating', 'body', 'locale', 'mark', 'variant', 'writtenAt']);
});

it('shows answered questions of every store, newest answer first, each signed by the store that answered', function () {
    ['product' => $product] = Fb::sold(['sa', 'eg']);
    $sara = Fx::customer();
    $ids = [];

    foreach (range(1, 11) as $n) {
        $ids[] = shopSectionsQuestion($product, $sara, sprintf('2026-10-01 10:%02d:00+00', $n), $n === 11 ? 'eg' : 'sa');
    }

    shopSectionsQuestion($product, $sara, '2026-10-03 10:00:00+00', 'sa', 'UNPUBLISHED');
    $first = shopSections(get(shopSectionsUrl($product)))['feedback.questions'];
    $second = shopSections(get(shopSectionsUrl($product, 'after[feedback.questions]='.$first['next'])))['feedback.questions'];
    $egypt = (string) app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId('eg')))?->name->in('en');

    expect(array_column([...$first['questions'], ...$second['questions']], 'questionId'))->toBe(array_reverse($ids))
        ->and([count($first['questions']), $second['next']])->toBe([10, null])
        ->and([$first['questions'][0]['answer'], $first['questions'][0]['store']])->toBe(['Yes.', $egypt])
        ->and(array_keys($first['questions'][0]))->toBe(['questionId', 'author', 'body', 'locale', 'answer', 'store', 'answeredAt']);
});

it('tells a signed-in customer what they may do and whether they saved it here', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'ae']);
    $sara = Fx::customer();
    Fx::actAsCustomer($sara);
    $sections = fn (): array => shopSections(get(shopSectionsUrl($product)));

    // Saved in another store: not saved here (answer 17).
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('ae'), 'saved_at' => CarbonImmutable::now()]);
    $before = $sections();

    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
    Fb::reviewRow($product, $sixty, $sara);
    $after = $sections();

    expect([$before['feedback.reviews']['canWrite'], $before['feedback.questions']['canAsk'], $before['feedback.wishlist']])->toBe([true, true, ['saved' => false]])
        // Written, though still waiting: no second one (§1.1).
        ->and([$after['feedback.reviews']['canWrite'], $after['feedback.wishlist']])->toBe([false, ['saved' => true]]);
});

it('gives a page that is not available now no sections', function () {
    ['product' => $product] = Fb::sold();
    Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $product)));

    expect(shopSections(get(shopSectionsUrl($product))))->toBe([]);
});

it('reads the sections in the same number of queries for one review as for ten, with questions', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $customers = array_map(static fn (int $n): string => Fx::customer("buyer{$n}@example.test"), range(1, 10));
    $approved = ['status' => 'APPROVED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()];
    $count = function () use ($product): int {
        app()->forgetScopedInstances();
        get(shopSectionsUrl($product))->assertOk();
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        get(shopSectionsUrl($product))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Fb::reviewRow($product, $sixty, $customers[0], $approved);
    shopSectionsQuestion($product, $customers[0], '2026-10-01 10:00:00+00');
    $one = $count();

    foreach (array_slice($customers, 1) as $n => $customer) {
        Fb::reviewRow($product, $sixty, $customer, $approved);
        shopSectionsQuestion($product, $customer, sprintf('2026-10-01 11:%02d:00+00', $n));
    }

    expect($count())->toBe($one);
});
