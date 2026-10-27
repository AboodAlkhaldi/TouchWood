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
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Actor;
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

    // Pricing's wholesale bands and Sales's Add to Cart come first (catalog.md amendment 35): no bands here, sold retail only.
    expect(array_keys($sections))->toBe(['pricing.wholesale', 'sales.cart', 'feedback.reviews', 'feedback.questions', 'feedback.wishlist'])
        ->and($sections['pricing.wholesale'])->toBeNull()
        ->and($sections['feedback.reviews'])->toBe(['average' => null, 'count' => 0, 'reviews' => [], 'next' => null, 'canWrite' => false, 'stars' => [
            ['star' => 5, 'count' => 0], ['star' => 4, 'count' => 0], ['star' => 3, 'count' => 0], ['star' => 2, 'count' => 0], ['star' => 1, 'count' => 0],
        ], 'star' => null, 'reviewed' => false])
        ->and($sections['feedback.questions'])->toBe(['questions' => [], 'next' => null, 'canAsk' => false])
        ->and($sections['feedback.wishlist'])->toBeNull();
});

it('shows the approved reviews of every store, newest first, ten at a time, with the rating', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'eg']);
    $ids = [];

    foreach (range(1, 12) as $n) {
        $ids[] = Fb::reviewRow($product, $sixty, Fx::customer("buyer{$n}@example.test"), [
            'status' => 'APPROVED', 'rating' => 4, 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(),
            'created_at' => sprintf('2026-10-01 10:%02d:00+00', $n),
        ], $n === 3 ? 'eg' : 'sa');
    }

    Fb::reviewRow($product, $sixty, Fx::customer('waiting@example.test'), ['created_at' => '2026-10-02 10:00:00+00']);
    $decided = ['moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(), 'created_at' => '2026-10-03 10:00:00+00'];
    Fb::reviewRow($product, $sixty, Fx::customer('refused@example.test'), ['status' => 'REJECTED', 'reason' => 'Off topic', ...$decided]);
    Fb::reviewRow($product, $sixty, Fx::customer('taken@example.test'), ['status' => 'UNPUBLISHED', ...$decided]);
    // The rating as Feedback keeps it: 4.25 shows 4.3, half up (amendment 25).
    DB::table('feedback.product_ratings')->insert(['product_id' => $product, 'rating_sum' => 17, 'rating_count' => 4, 'updated_at' => CarbonImmutable::now()]);

    $first = shopSections(get(shopSectionsUrl($product)))['feedback.reviews'];
    $second = shopSections(get(shopSectionsUrl($product, 'after[feedback.reviews]='.$first['next'])))['feedback.reviews'];

    expect(array_column([...$first['reviews'], ...$second['reviews']], 'reviewId'))->toBe(array_reverse($ids))
        ->and([$first['average'], $first['count'], count($first['reviews'])])->toBe([4.3, 4, 10])
        ->and($first['next'])->toBe($ids[2])
        ->and(count($second['reviews']))->toBe(2)
        ->and($second['next'])->toBeNull()
        ->and($first['reviews'][0]['author'])->toBe(['firstName' => 'Sara', 'lastInitial' => 'A', 'deleted' => false])
        ->and([$first['reviews'][0]['rating'], $first['reviews'][0]['mark'], $first['reviews'][0]['variant'][0]['value']])->toBe([4, 'VERIFIED_PURCHASE', '60 cm'])
        ->and(array_keys($first['reviews'][0]))->toBe(['reviewId', 'author', 'rating', 'body', 'locale', 'mark', 'variant', 'writtenAt'])
        ->and(CarbonImmutable::parse($first['reviews'][0]['writtenAt'])->equalTo(CarbonImmutable::parse('2026-10-01 10:12:00+00')))->toBeTrue();
});

it('shows answered questions of every store, newest answer first, each signed by the store that answered', function () {
    ['product' => $product] = Fb::sold(['sa', 'eg']);
    $sara = Fx::customer();
    $ids = [];

    foreach (range(1, 12) as $n) {
        $ids[] = shopSectionsQuestion($product, $sara, sprintf('2026-10-01 10:%02d:00+00', $n), $n === 12 ? 'eg' : 'sa');
    }

    shopSectionsQuestion($product, $sara, '2026-10-03 10:00:00+00', 'sa', 'UNPUBLISHED');
    $first = shopSections(get(shopSectionsUrl($product)))['feedback.questions'];
    $second = shopSections(get(shopSectionsUrl($product, 'after[feedback.questions]='.$first['next'])))['feedback.questions'];
    $egypt = (string) app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId('eg')))?->name->in('en');

    expect(array_column([...$first['questions'], ...$second['questions']], 'questionId'))->toBe(array_reverse($ids))
        ->and([count($first['questions']), count($second['questions']), $second['next']])->toBe([10, 2, null])
        ->and(CarbonImmutable::parse($first['questions'][0]['answeredAt'])->equalTo(CarbonImmutable::parse('2026-10-01 10:12:00+00')))->toBeTrue()
        ->and([$first['questions'][0]['answer'], $first['questions'][0]['store']])->toBe(['Yes.', $egypt])
        ->and(array_keys($first['questions'][0]))->toBe(['questionId', 'author', 'body', 'locale', 'answer', 'store', 'answeredAt']);
});

it('ends a page of exactly ten with no next, and a place it does not hold with nothing', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $sara = Fx::customer();

    foreach (range(1, 10) as $n) {
        shopSectionsQuestion($product, $sara, sprintf('2026-10-01 10:%02d:00+00', $n));
        Fb::reviewRow($product, $sixty, Fx::customer("buyer{$n}@example.test"), ['status' => 'APPROVED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]);
    }

    $page = shopSections(get(shopSectionsUrl($product)));
    $full = $page['feedback.questions'];
    $unknown = shopSections(get(shopSectionsUrl($product, 'after[feedback.questions]='.strtolower((string) Str::ulid()))))['feedback.questions'];
    $notOne = shopSections(get(shopSectionsUrl($product, 'after[feedback.questions]=nowhere')))['feedback.questions'];
    $nested = shopSections(get(shopSectionsUrl($product, 'after[feedback.questions][]=x&after[0]=y')))['feedback.questions'];

    expect([count($full['questions']), $full['next']])->toBe([10, null])
        ->and([count($page['feedback.reviews']['reviews']), $page['feedback.reviews']['next']])->toBe([10, null])
        ->and($unknown['questions'])->toBe([])
        // Not an id: from the top, as anything the address cannot mean.
        ->and([count($notOne['questions']), count($nested['questions'])])->toBe([10, 10]);
});

it('never shows one customer\'s own state to another or to a guest, though the page itself is kept', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $sara = Fx::customer();
    $layla = Fx::customer('layla@example.test');
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
    Fb::reviewRow($product, $sixty, $sara);
    Fb::delivered($layla, $product, $sixty);
    $state = static fn (array $sections): array => [$sections['feedback.reviews']['canWrite'], $sections['feedback.wishlist']];

    Fx::actAsCustomer($sara);
    $saras = $state(shopSections(get(shopSectionsUrl($product))));
    Fx::actAsCustomer($layla);
    $laylas = $state(shopSections(get(shopSectionsUrl($product))));
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    $guests = $state(shopSections(get(shopSectionsUrl($product))));

    expect([$saras, $laylas, $guests])->toBe([[false, ['saved' => true]], [true, ['saved' => false]], [false, null]]);
});

it('reads the page in Arabic: the store\'s name and the size in Arabic, a deleted author as deleted', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $gone = Fx::customer('gone@example.test');
    DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => CarbonImmutable::now()]);
    Fb::reviewRow($product, $sixty, $gone, ['status' => 'APPROVED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]);
    shopSectionsQuestion($product, $gone, '2026-10-01 10:00:00+00');
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', 'ar')->where('is_current', true)->value('slug');

    $sections = shopSections(get("/sa/ar/products/{$slug}"));
    $size = app(CatalogApi::class)->variants([$sixty])[$sixty]->values[0];

    expect($sections['feedback.reviews']['reviews'][0]['author'])->toBe(['firstName' => '', 'lastInitial' => '', 'deleted' => true])
        ->and($sections['feedback.reviews']['reviews'][0]['variant'][0])->toBe(['attribute' => $size->attribute->in('ar'), 'value' => $size->value->in('ar')])
        ->and($sections['feedback.questions']['questions'][0]['store'])->toBe((string) app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId('sa')))?->name->in('ar'))
        ->and($sections['feedback.questions']['questions'][0]['author']['deleted'])->toBeTrue();
});

it('tells a signed-in customer what they may do and whether they saved it here', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'ae']);
    $sara = Fx::customer();
    Fx::actAsCustomer($sara);
    $sections = fn (): array => shopSections(get(shopSectionsUrl($product)));

    // Never delivered to her: no Write a Review (owner's Q14), though she may ask.
    Fb::sales();
    $unbought = $sections();
    Fb::delivered($sara, $product, $sixty);
    // Saved in another store: not saved here (answer 17).
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('ae'), 'saved_at' => CarbonImmutable::now()]);
    $before = $sections();

    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
    Fb::reviewRow($product, $sixty, $sara);
    $after = $sections();

    expect([$unbought['feedback.reviews']['canWrite'], $unbought['feedback.questions']['canAsk']])->toBe([false, true])
        ->and([$before['feedback.reviews']['canWrite'], $before['feedback.questions']['canAsk'], $before['feedback.wishlist']])->toBe([true, true, ['saved' => false]])
        // Written, though still waiting: no second one (§1.1).
        ->and([$after['feedback.reviews']['canWrite'], $after['feedback.wishlist']])->toBe([false, ['saved' => true]]);
});

it('gives a page that is not available now the Save heart alone (catalog.md amendment 34)', function () {
    ['product' => $product] = Fb::sold();
    $sara = Fx::customer();
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
    Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $product)));

    Fx::actAsCustomer($sara);
    $saras = shopSections(get(shopSectionsUrl($product)));
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    $guests = shopSections(get(shopSectionsUrl($product)));

    // No reviews, no questions: what is saved can still be kept or removed there.
    expect($saras)->toBe(['feedback.wishlist' => ['saved' => true]])
        ->and($guests)->toBe(['feedback.wishlist' => null]);
});

it('breaks the approved reviews down by star, and pages one star alone as the address asks (§4.2 B1)', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $approved = fn (int $rating, int $n): string => Fb::reviewRow($product, $sixty, Fx::customer("buyer{$n}@example.test"), [
        'status' => 'APPROVED', 'rating' => $rating, 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now(),
        'created_at' => sprintf('2026-10-01 10:%02d:00+00', $n),
    ]);
    $fives = array_map(fn (int $n): string => $approved(5, $n), range(1, 11));
    $approved(4, 20);
    $approved(4, 21);
    $approved(2, 22);
    // Not approved: never counted.
    Fb::reviewRow($product, $sixty, Fx::customer('refused@example.test'), ['status' => 'REJECTED', 'rating' => 1, 'reason' => 'Off topic', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]);
    Fb::reviewRow($product, $sixty, Fx::customer('waiting@example.test'), ['rating' => 1]);

    $all = shopSections(get(shopSectionsUrl($product)))['feedback.reviews'];
    $five = shopSections(get(shopSectionsUrl($product, 'filter[feedback.reviews]=5')))['feedback.reviews'];
    $more = shopSections(get(shopSectionsUrl($product, 'filter[feedback.reviews]=5&after[feedback.reviews]='.$five['next'])))['feedback.reviews'];
    $none = shopSections(get(shopSectionsUrl($product, 'filter[feedback.reviews]=1')))['feedback.reviews'];

    expect($all['stars'])->toBe([['star' => 5, 'count' => 11], ['star' => 4, 'count' => 2], ['star' => 3, 'count' => 0], ['star' => 2, 'count' => 1], ['star' => 1, 'count' => 0]])
        ->and([$all['star'], count($all['reviews'])])->toBe([null, 10])
        ->and($five['star'])->toBe(5)
        ->and(array_column([...$five['reviews'], ...$more['reviews']], 'reviewId'))->toBe(array_reverse($fives))
        ->and($more['next'])->toBeNull()
        ->and($five['stars'])->toBe($all['stars'])
        ->and([$none['reviews'], $none['next'], $none['star']])->toBe([[], null, 1]);
});

it('takes no star but 1 to 5: anything else shows every review', function (string $filter) {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    Fb::reviewRow($product, $sixty, Fx::customer('buyer@example.test'), ['status' => 'APPROVED', 'rating' => 3, 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]);

    $section = shopSections(get(shopSectionsUrl($product, 'filter[feedback.reviews]='.$filter)))['feedback.reviews'];

    expect([$section['star'], count($section['reviews'])])->toBe([null, 1]);
})->with(['0', '6', 'five', '4.5', '%20']);

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

    // Measured 2026-10-19, a guest, the session in memory: the shop's own 6, then Feedback's one
    // statement, the authors' names 1, the sizes bought 2 (Catalog's variants and their values) and
    // the stores' names 1 - the limits a target, not a cap (owner, 2026-10-19).
    expect([$one, $count()])->toBe([11, 11]);
});
