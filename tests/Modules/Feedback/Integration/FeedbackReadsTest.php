<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\ListFavourites\ListFavourites;
use Modules\Feedback\Application\Query\ListFavourites\ListFavouritesHandler;
use Modules\Feedback\Application\Query\ListQuestions\ListQuestions;
use Modules\Feedback\Application\Query\ListQuestions\ListQuestionsHandler;
use Modules\Feedback\Application\Query\ListReviews\ListReviews;
use Modules\Feedback\Application\Query\ListReviews\ListReviewsHandler;
use Modules\Feedback\Application\Query\ViewCustomerWishlist\ViewCustomerWishlist;
use Modules\Feedback\Application\Query\ViewCustomerWishlist\ViewCustomerWishlistHandler;
use Modules\Feedback\Application\Query\ViewMyQuestions\ViewMyQuestions;
use Modules\Feedback\Application\Query\ViewMyQuestions\ViewMyQuestionsHandler;
use Modules\Feedback\Application\Query\ViewMyReviews\ViewMyReviews;
use Modules\Feedback\Application\Query\ViewMyReviews\ViewMyReviewsHandler;
use Modules\Feedback\Application\Query\ViewMyWishlist\ViewMyWishlist;
use Modules\Feedback\Application\Query\ViewMyWishlist\ViewMyWishlistHandler;
use Modules\Feedback\Application\Query\WishlistGroup;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback's reads (feedback.md §1.1, §1.3, §1.4, §3, §8): my reviews, my questions and my wishlist
| for the signed-in customer only; a store's review and question queues, a customer's wishlist and
| "Favourited products" for its staff only — authors in their public form, keyset pages.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A question written straight into its table, at a given moment.
 *
 * @param  array<string, mixed>  $with
 */
function readsQuestion(string $productId, string $customerId, string $at, array $with = [], string $storeCode = 'sa'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('feedback.questions')->insert([
        'id' => $id, 'product_id' => $productId, 'store_id' => Fx::storeId($storeCode), 'customer_id' => $customerId,
        'body' => "Asked at {$at}", 'locale' => 'en', 'status' => 'PENDING', 'created_at' => $at, 'updated_at' => $at, ...$with,
    ]);

    return $id;
}

function readsStoreName(string $code): string
{
    return (string) app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId($code)))?->name->in('en');
}

function readsWishlist(string $customerId, string $productId, string $storeCode, string $at): void
{
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $customerId, 'product_id' => $productId, 'store_id' => Fx::storeId($storeCode), 'saved_at' => $at]);
}

describe('my reviews and questions', function () {
    it('lists my reviews in every store, newest first, each as its store shows it, with the reason given', function () {
        ['product' => $kept, 'variants' => [$sixty]] = Fb::sold(['sa', 'eg']);
        ['product' => $taken, 'variants' => [, $eighty]] = Fb::sold(['eg']);
        $sara = Fx::customer();
        $layla = Fx::customer('layla@example.test');
        $older = Fb::reviewRow($kept, $sixty, $sara, ['created_at' => '2026-10-01 10:00:00+00', 'rating' => 5]);
        $newer = Fb::reviewRow($taken, $eighty, $sara, ['created_at' => '2026-10-02 10:00:00+00', 'status' => 'REJECTED', 'reason' => 'Off topic', 'moderated_by' => Fx::staff(), 'moderated_at' => '2026-10-02 11:00:00+00'], 'eg');
        Fb::reviewRow($kept, $sixty, $layla);
        Fx::actAsCustomer($sara);

        $page = app(ViewMyReviewsHandler::class)->handle(new ViewMyReviews('en'));

        expect(array_map(fn ($row) => $row->reviewId, $page->rows))->toBe([$newer, $older])
            ->and($page->next)->toBeNull()
            ->and([$page->rows[0]->storeId, $page->rows[0]->status, $page->rows[0]->reason])->toBe([Fx::storeId('eg'), ReviewStatus::Rejected, 'Off topic'])
            ->and([$page->rows[1]->rating, $page->rows[1]->mark, $page->rows[1]->reason])->toBe([5, ReviewMark::VerifiedPurchase, null])
            ->and($page->rows[1]->variant[0]->attribute)->toStartWith('Width')
            ->and($page->rows[1]->variant[0]->value)->toBe('60 cm')
            ->and([$page->rows[0]->variant[0]->value])->toBe(['80 cm'])
            // Each product read in the store the review was written in: taken sold there, kept sold there too.
            ->and([$page->rows[0]->product->productId, $page->rows[0]->product->sold, $page->rows[1]->product->sold])->toBe([$taken, true, true]);
    });

    it('lists my questions newest first, the answer signed by the store asked in, a page of 20 at a time', function () {
        ['product' => $product] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();
        $staff = Fx::staff();
        $ids = [];

        foreach (range(1, 21) as $n) {
            $ids[] = readsQuestion($product, $sara, sprintf('2026-10-01 10:%02d:00+00', $n));
        }

        $answered = readsQuestion($product, $sara, '2026-10-02 10:00:00+00', ['status' => 'ANSWERED', 'answer' => 'Yes.', 'answered_by' => $staff, 'answered_at' => '2026-10-02 12:00:00+00'], 'eg');
        $taken = readsQuestion($product, $sara, '2026-10-03 10:00:00+00', ['status' => 'UNPUBLISHED', 'answer' => 'No.', 'answered_by' => $staff, 'answered_at' => '2026-10-03 12:00:00+00', 'reason' => 'Out of date']);
        readsQuestion($product, Fx::customer('layla@example.test'), '2026-10-04 10:00:00+00');
        Fx::actAsCustomer($sara);

        $first = app(ViewMyQuestionsHandler::class)->handle(new ViewMyQuestions('en'));
        $second = app(ViewMyQuestionsHandler::class)->handle(new ViewMyQuestions('en', strtoupper((string) $first->next)));
        $all = array_merge($first->rows, $second->rows);

        expect(array_map(fn ($row) => $row->questionId, $all))->toBe([$taken, $answered, ...array_reverse($ids)])
            ->and(count($first->rows))->toBe(20)
            ->and($first->next)->toBe($first->rows[19]->questionId)
            ->and(count($second->rows))->toBe(3)
            ->and($second->next)->toBeNull()
            ->and([$all[1]->status, $all[1]->answer, $all[1]->storeName])->toBe([QuestionStatus::Answered, 'Yes.', readsStoreName('eg')])
            // Taken down: still mine to read, the answer and the reason with it (answer 18).
            ->and([$all[0]->status, $all[0]->answer, $all[0]->reason, $all[0]->storeName])->toBe([QuestionStatus::Unpublished, 'No.', 'Out of date', readsStoreName('sa')])
            ->and([$all[2]->answer, $all[2]->answeredAt])->toBe([null, null]);
    });

    it('is for the signed-in customer only, and refuses a place that is not one', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(ViewMyReviewsHandler::class)->handle(new ViewMyReviews('en')))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewMyQuestionsHandler::class)->handle(new ViewMyQuestions('en')))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewMyWishlistHandler::class)->handle(new ViewMyWishlist('en')))->toThrow(Unauthorized::class);

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        expect(fn () => app(ViewMyWishlistHandler::class)->handle(new ViewMyWishlist('en')))->toThrow(Unauthorized::class);

        Fx::actAsCustomer(Fx::customer());
        expect(Fb::thrown(fn () => app(ViewMyReviewsHandler::class)->handle(new ViewMyReviews('en', 'nowhere')), InvalidFeedbackAttribute::class)->attribute)->toBe('after')
            ->and(Fb::thrown(fn () => app(ViewMyQuestionsHandler::class)->handle(new ViewMyQuestions('fr')), InvalidFeedbackAttribute::class)->attribute)->toBe('locale')
            ->and(app(ViewMyQuestionsHandler::class)->handle(new ViewMyQuestions('en', strtolower((string) Str::ulid())))->rows)->toBe([]);
    });
});

describe('my wishlist', function () {
    it('groups my items by the store they were saved in, unavailable judged there', function () {
        ['product' => $sold] = Fb::sold(['sa', 'ae', 'eg']);
        ['product' => $paused] = Fb::sold(['sa']);
        ['product' => $archived] = Fb::sold(['sa']);
        $sara = Fx::customer();
        readsWishlist($sara, $sold, 'eg', '2026-10-01 10:00:00+00');
        readsWishlist($sara, $sold, 'sa', '2026-10-02 10:00:00+00');
        readsWishlist($sara, $paused, 'sa', '2026-10-03 10:00:00+00');
        readsWishlist($sara, $archived, 'sa', '2026-10-04 10:00:00+00');
        readsWishlist($sara, $sold, 'ae', '2026-10-05 10:00:00+00');
        readsWishlist(Fx::customer('layla@example.test'), $sold, 'sa', '2026-10-06 10:00:00+00');
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $paused)));
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived)));
        // An off store is not found by its code any more: the ids, kept from before.
        [$sa, $ae, $eg] = [Fx::storeId('sa'), Fx::storeId('ae'), Fx::storeId('eg')];
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $order = array_map(static fn ($store): string => strtolower($store->id), app(PlatformApi::class)->allStores());
        Fx::actAsCustomer($sara);

        $groups = app(ViewMyWishlistHandler::class)->handle(new ViewMyWishlist('en'));
        $shape = array_map(static fn (WishlistGroup $group): array => [
            $group->storeId, $group->storeOpen,
            array_map(static fn ($item): array => [$item->product->productId, $item->unavailable], $group->items),
        ], $groups);
        $expected = [
            $sa => [$sa, true, [[$archived, true], [$paused, true], [$sold, false]]],
            $ae => [$ae, true, [[$sold, false]]],
            // An off store's items: every one unavailable, though the product is still chosen there.
            $eg => [$eg, false, [[$sold, true]]],
        ];

        expect($shape)->toBe(array_values(array_filter(array_map(static fn (string $id): ?array => $expected[$id] ?? null, $order))))
            ->and($groups[0]->storeName)->toBe(readsStoreName('sa'));
    });
    it('reads the same number of queries for 2 items as for 6 (feedback.md §8)', function () {
        $sara = Fx::customer();
        $products = array_map(static fn (): string => Fb::sold(['sa'])['product'], range(1, 6));
        $count = function () use ($sara): int {
            Fx::actAsCustomer($sara);
            $queries = Cx::recordQueries();
            app(ViewMyWishlistHandler::class)->handle(new ViewMyWishlist('en'));

            return count($queries);
        };

        foreach (array_slice($products, 0, 2) as $product) {
            readsWishlist($sara, $product, 'sa', '2026-10-01 10:00:00+00');
        }

        $few = $count();

        foreach (array_slice($products, 2) as $product) {
            readsWishlist($sara, $product, 'sa', '2026-10-01 10:00:00+00');
        }

        expect($count())->toBe($few);
    });
});

describe('the staff queues', function () {
    it('lists a store\'s waiting reviews oldest first and decided ones newest first, authors in their public form', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();
        $layla = Fx::customer('layla@example.test');
        $gone = Fx::customer('gone@example.test');
        DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => CarbonImmutable::now()]);
        $moderator = Fx::staff(firstName: 'Mona');
        $first = Fb::reviewRow($product, $sixty, $sara, ['created_at' => '2026-10-01 10:00:00+00']);
        $second = Fb::reviewRow($product, $sixty, $gone, ['created_at' => '2026-10-02 10:00:00+00']);
        Fb::reviewRow($product, $sixty, $layla, ['created_at' => '2026-10-03 10:00:00+00'], 'eg');
        $decided = ['status' => 'APPROVED', 'moderated_by' => $moderator, 'moderated_at' => '2026-10-05 10:00:00+00'];
        $olderApproved = Fb::reviewRow(Fb::sold()['product'], $sixty, $sara, ['created_at' => '2026-10-01 09:00:00+00', ...$decided]);
        $newerApproved = Fb::reviewRow(Fb::sold()['product'], $sixty, $sara, ['created_at' => '2026-10-04 09:00:00+00', ...$decided]);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

        $waiting = app(ListReviewsHandler::class)->handle(new ListReviews(Fx::storeId('sa'), 'PENDING', 'en'));
        $approved = app(ListReviewsHandler::class)->handle(new ListReviews(Fx::storeId('sa'), 'APPROVED', 'en'));

        expect(array_map(fn ($row) => $row->reviewId, $waiting->rows))->toBe([$first, $second])
            ->and([$waiting->rows[0]->author->firstName, $waiting->rows[0]->author->lastInitial, $waiting->rows[0]->author->deleted])->toBe(['Sara', 'A', false])
            ->and([$waiting->rows[1]->author->firstName, $waiting->rows[1]->author->deleted])->toBe(['', true])
            ->and([$waiting->rows[0]->moderatedBy, $waiting->rows[0]->variant[0]->value])->toBe([null, '60 cm'])
            ->and(array_map(fn ($row) => $row->reviewId, $approved->rows))->toBe([$newerApproved, $olderApproved])
            ->and($approved->rows[0]->moderatedBy?->name)->toStartWith('Mona');
    });

    it('lists a store\'s questions 50 a page, and only for its staff', function () {
        ['product' => $product] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();
        $ids = [];

        foreach (range(1, 51) as $n) {
            $ids[] = readsQuestion($product, $sara, sprintf('2026-10-01 10:%02d:00+00', $n));
        }

        readsQuestion($product, $sara, '2026-10-01 09:00:00+00', [], 'eg');
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));

        $first = app(ListQuestionsHandler::class)->handle(new ListQuestions(Fx::storeId('sa'), 'PENDING', 'en'));
        $second = app(ListQuestionsHandler::class)->handle(new ListQuestions(Fx::storeId('sa'), 'PENDING', 'en', $first->next));

        expect(array_map(fn ($row) => $row->questionId, [...$first->rows, ...$second->rows]))->toBe($ids)
            ->and([count($first->rows), $second->next])->toBe([50, null])
            ->and($first->rows[0]->author->firstName)->toBe('Sara')
            ->and(fn () => app(ListQuestionsHandler::class)->handle(new ListQuestions(Fx::storeId('eg'), 'PENDING', 'en')))->toThrow(Unauthorized::class)
            ->and(fn () => app(ListReviewsHandler::class)->handle(new ListReviews(Fx::storeId('sa'), 'PENDING', 'en')))->toThrow(Unauthorized::class)
            ->and(Fb::thrown(fn () => app(ListQuestionsHandler::class)->handle(new ListQuestions(Fx::storeId('sa'), 'HIDDEN', 'en')), InvalidFeedbackAttribute::class)->attribute)->toBe('status')
            ->and(Fb::thrown(fn () => app(ListQuestionsHandler::class)->handle(new ListQuestions('not-a-store', 'PENDING', 'en')), InvalidFeedbackAttribute::class)->attribute)->toBe('store');

        Fx::actAsCustomer($sara);
        expect(fn () => app(ListQuestionsHandler::class)->handle(new ListQuestions(Fx::storeId('sa'), 'PENDING', 'en')))->toThrow(Unauthorized::class);
    });

    it('shows an off store\'s queue only to those who may switch stores', function () {
        Fb::sold(['eg']);
        $egypt = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['*']));
        expect(fn () => app(ListQuestionsHandler::class)->handle(new ListQuestions($egypt, 'PENDING', 'en')))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(app(ListQuestionsHandler::class)->handle(new ListQuestions($egypt, 'PENDING', 'en'))->rows)->toBe([]);
    });
});

describe('wishlists for staff', function () {
    it('shows a customer\'s items of the reader\'s stores only, and nothing of a customer they may not see', function () {
        ['product' => $product] = Fb::sold(['sa', 'ae']);
        $sara = Fx::customer();
        readsWishlist($sara, $product, 'sa', '2026-10-01 10:00:00+00');
        readsWishlist($sara, $product, 'ae', '2026-10-02 10:00:00+00');
        $read = fn (string $customer): array => array_map(static fn (WishlistGroup $group): string => $group->storeId, app(ViewCustomerWishlistHandler::class)->handle(new ViewCustomerWishlist($customer, 'en')));

        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::WISHLIST_VIEW, 'access.customer.view'], ['sa']));
        expect($read(strtoupper($sara)))->toBe([Fx::storeId('sa')])
            ->and($read(strtolower((string) Str::ulid())))->toBe([])
            ->and($read('not-a-customer'))->toBe([]);

        // The UAE's wishlists, but Sara is a customer of KSA, whom this reader may not see: nothing.
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::WISHLIST_VIEW, 'access.customer.view'], ['ae']));
        expect($read($sara))->toBe([]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect($read($sara))->toBe([Fx::storeId('sa'), Fx::storeId('ae')]);

        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['*']));
        expect(fn () => $read($sara))->toThrow(Unauthorized::class);
    });

    it('counts how many customers saved each product in a store, most saved first, without archived ones', function () {
        ['product' => $three] = Fb::sold(['sa']);
        ['product' => $twoA] = Fb::sold(['sa']);
        ['product' => $twoB] = Fb::sold(['sa']);
        ['product' => $archived] = Fb::sold(['sa']);
        $customers = [Fx::customer('a@example.test'), Fx::customer('b@example.test'), Fx::customer('c@example.test'), Fx::customer('d@example.test')];

        foreach ([$three => 3, $twoA => 2, $twoB => 2, $archived => 4] as $product => $times) {
            foreach (array_slice($customers, 0, $times) as $customer) {
                readsWishlist($customer, $product, 'sa', '2026-10-01 10:00:00+00');
            }
        }

        readsWishlist($customers[3], $twoA, 'ae', '2026-10-01 10:00:00+00');
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived)));
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::WISHLIST_VIEW], ['sa']));
        $twos = [$twoA, $twoB];
        sort($twos);

        $page = app(ListFavouritesHandler::class)->handle(new ListFavourites(Fx::storeId('sa'), 'en'));
        $after = app(ListFavouritesHandler::class)->handle(new ListFavourites(Fx::storeId('sa'), 'en', "2:{$twos[0]}"));

        expect(array_map(fn ($row) => [$row->product->productId, $row->savedBy], $page->rows))->toBe([[$three, 3], [$twos[0], 2], [$twos[1], 2]])
            ->and($page->next)->toBeNull()
            ->and(array_map(fn ($row) => $row->product->productId, $after->rows))->toBe([$twos[1]])
            ->and(Fb::thrown(fn () => app(ListFavouritesHandler::class)->handle(new ListFavourites(Fx::storeId('sa'), 'en', 'two')), InvalidFeedbackAttribute::class)->attribute)->toBe('after')
            ->and(fn () => app(ListFavouritesHandler::class)->handle(new ListFavourites(Fx::storeId('ae'), 'en')))->toThrow(Unauthorized::class);
    });
});
