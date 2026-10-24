<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Feedback\Application\Command\BulkDecideQuestions\BulkDecideQuestions;
use Modules\Feedback\Application\Command\BulkDecideQuestions\BulkDecideQuestionsHandler;
use Modules\Feedback\Application\Command\BulkDecideReviews\BulkDecideReviews;
use Modules\Feedback\Application\Command\BulkDecideReviews\BulkDecideReviewsHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Moderation\BulkDecision;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Decisions on many at once (feedback.md §2.4 B2): approve, reject (one optional reason) and unpublish up to a
| page of one store's reviews, reject and unpublish its questions — each decided on its own, under its own
| lock, audited as one; the answer names those done, those someone else decided first, and those not found.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Waiting reviews of one product, written in the store.
 *
 * @return list<string>
 */
function bulkReviews(int $count, string $storeCode = 'sa', string $status = 'PENDING'): array
{
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold([$storeCode]);
    $decided = $status === 'PENDING' ? [] : ['moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()];

    return array_map(fn (int $n): string => Fb::reviewRow($product, $sixty, Fx::customer("bulk{$storeCode}{$status}{$n}@example.test"), ['status' => $status, ...$decided], $storeCode), range(1, $count));
}

/** @return list<string> waiting questions asked in the store */
function bulkQuestions(int $count, string $storeCode = 'sa'): array
{
    ['product' => $product] = Fb::sold([$storeCode]);
    $ids = [];

    foreach (range(1, $count) as $n) {
        $id = strtolower((string) Str::ulid());
        DB::table('feedback.questions')->insert([
            'id' => $id, 'product_id' => $product, 'store_id' => Fx::storeId($storeCode), 'customer_id' => Fx::customer("asker{$storeCode}{$n}@example.test"),
            'body' => 'Does it fit?', 'locale' => 'en', 'status' => 'PENDING', 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);
        $ids[] = $id;
    }

    return $ids;
}

/** @param  list<string>  $ids */
function bulkReviewsDo(string $action, array $ids, ?string $reason = null, string $storeCode = 'sa'): BulkDecision
{
    return app(BulkDecideReviewsHandler::class)->handle(new BulkDecideReviews(Fx::storeId($storeCode), $action, $ids, $reason));
}

/** @return array<string, string> id => status */
function bulkStatuses(string $table): array
{
    return DB::table($table)->pluck('status', 'id')->map(fn ($status): string => (string) $status)->all();
}

describe('reviews', function () {
    it('approves many at once, each on its own, audited one by one', function () {
        $ids = bulkReviews(3);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

        $decision = bulkReviewsDo('approve', $ids);

        expect($decision->done)->toBe($ids)
            ->and([$decision->alreadyDecided, $decision->notFound])->toBe([[], []])
            ->and(array_unique(array_values(bulkStatuses('feedback.reviews'))))->toBe(['APPROVED'])
            ->and(Fx::audits('feedback.review.approved'))->toBe(3)
            ->and((int) DB::table('feedback.product_ratings')->value('rating_count'))->toBe(3);
    });

    it('rejects many with one reason for all, and names those already decided and those not found', function () {
        $waiting = bulkReviews(2);
        $approved = bulkReviews(1, status: 'APPROVED');
        $other = bulkReviews(1, 'eg');
        $unknown = strtolower((string) Str::ulid());
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa', 'eg']));

        $decision = bulkReviewsDo('reject', [...$waiting, ...$approved, ...$other, $unknown], 'Off topic');

        expect([$decision->done, $decision->alreadyDecided, $decision->notFound])->toBe([$waiting, $approved, [...$other, $unknown]])
            ->and(DB::table('feedback.reviews')->whereIn('id', $waiting)->pluck('reason')->unique()->all())->toBe(['Off topic'])
            ->and(DB::table('feedback.reviews')->where('id', $other[0])->value('status'))->toBe('PENDING');
    });

    it('unpublishes many live reviews, with or without a reason', function (?string $reason) {
        $ids = bulkReviews(2);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));
        // Approved as ever, so the rating counts them.
        bulkReviewsDo('approve', $ids);

        expect(bulkReviewsDo('unpublish', $ids, $reason)->done)->toBe($ids)
            ->and(DB::table('feedback.reviews')->whereIn('id', $ids)->pluck('status')->unique()->all())->toBe(['UNPUBLISHED'])
            ->and(DB::table('feedback.reviews')->whereIn('id', $ids)->pluck('reason')->unique()->all())->toBe([$reason]);
    })->with(['a reason' => ['Wrong product'], 'none' => [null]]);

    it('refuses what is not a decision on a page of ids, before reading any', function (string $action, Closure $ids, string $attribute) {
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

        expect(Fb::thrown(fn () => bulkReviewsDo($action, $ids()), InvalidFeedbackAttribute::class)->attribute)->toBe($attribute);
    })->with([
        'no such action' => ['delete', fn () => [strtolower((string) Str::ulid())], 'action'],
        'none' => ['approve', fn () => [], 'ids'],
        'more than a page' => ['approve', fn () => array_map(fn () => strtolower((string) Str::ulid()), range(1, 51)), 'ids'],
        'one twice' => ['approve', function () {
            $id = strtolower((string) Str::ulid());

            return [$id, strtoupper($id)];
        }, 'ids'],
        'not an id' => ['approve', fn () => ['review-1'], 'ids'],
    ]);

    it('lets only the store moderators decide, never a customer', function () {
        $ids = bulkReviews(1);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['eg']));
        expect(fn () => bulkReviewsDo('approve', $ids))->toThrow(Unauthorized::class);

        Fx::actAsCustomer(Fx::customer('shopper@example.test'));
        expect(fn () => bulkReviewsDo('approve', $ids))->toThrow(Unauthorized::class)
            ->and(bulkStatuses('feedback.reviews')[$ids[0]])->toBe('PENDING');
    });
});

describe('questions', function () {
    it('rejects and unpublishes many questions, naming those already decided', function () {
        $ids = bulkQuestions(3);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));
        $handler = app(BulkDecideQuestionsHandler::class);

        $first = $handler->handle(new BulkDecideQuestions(Fx::storeId('sa'), 'reject', [$ids[0], $ids[1]], 'Not about the product'));
        $second = $handler->handle(new BulkDecideQuestions(Fx::storeId('sa'), 'reject', [$ids[1], $ids[2]]));

        expect([$first->done, $first->alreadyDecided])->toBe([[$ids[0], $ids[1]], []])
            ->and([$second->done, $second->alreadyDecided])->toBe([[$ids[2]], [$ids[1]]])
            ->and(DB::table('feedback.questions')->where('id', $ids[0])->value('reason'))->toBe('Not about the product')
            ->and(Fx::audits('feedback.question.rejected'))->toBe(3)
            ->and(Fb::thrown(fn () => $handler->handle(new BulkDecideQuestions(Fx::storeId('sa'), 'approve', $ids)), InvalidFeedbackAttribute::class)->attribute)->toBe('action');
    });
});
