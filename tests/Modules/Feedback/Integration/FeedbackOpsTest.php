<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Feedback\Application\Command\AnswerQuestion\AnswerQuestion;
use Modules\Feedback\Application\Command\AnswerQuestion\AnswerQuestionHandler;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReview;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReviewHandler;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestion;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestionHandler;
use Modules\Feedback\Application\Command\BulkDecideQuestions\BulkDecideQuestions;
use Modules\Feedback\Application\Command\BulkDecideQuestions\BulkDecideQuestionsHandler;
use Modules\Feedback\Application\Command\BulkDecideReviews\BulkDecideReviews;
use Modules\Feedback\Application\Command\BulkDecideReviews\BulkDecideReviewsHandler;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestion;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestionHandler;
use Modules\Feedback\Application\Command\RejectReview\RejectReview;
use Modules\Feedback\Application\Command\RejectReview\RejectReviewHandler;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestion;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestionHandler;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReview;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReviewHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Feedback\Public\Dto\FavouriteCountDto;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Modules\Feedback\Public\Events\QuestionAnswered;
use Modules\Feedback\Public\Events\QuestionNotPublished;
use Modules\Feedback\Public\Events\ReviewNotPublished;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| What Feedback offers Ops (feedback.md §2.5, §6; ops.md §1.5 C16, C17, §1.10, §1.11, §2.3): the three
| events — a question answered; a question or a review not published, only with a reason — through the
| outbox, ids only; and the reads behind its messages, its Favourites report and its daily report.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * The events of a kind in the outbox, as their payloads.
 *
 * @return list<array<string, mixed>>
 */
function feedbackOpsEvents(string $type): array
{
    return array_values(DB::table('public.outbox_messages')->where('type', $type)->orderBy('id')->pluck('payload')
        ->map(static fn (string $payload): array => (array) json_decode($payload, true, flags: JSON_THROW_ON_ERROR))->all());
}

function feedbackOpsAsk(string $customerId, string $productId, string $body = 'Does it fit a 60 cm cabinet?'): string
{
    Fx::actAsCustomer($customerId);

    return app(AskQuestionHandler::class)->handle(new AskQuestion(Fx::storeId('sa'), $productId, $body));
}

/** @return array{product: string, review: string, customer: string} */
function feedbackOpsReview(string $email = 'sara@example.test'): array
{
    $sold = $GLOBALS['feedbackOpsSold'] ??= Fb::sold();
    $customer = Fx::customer($email);
    Fb::delivered($customer, $sold['product'], $sold['variants'][0]);

    return ['product' => $sold['product'], 'review' => Fb::write($customer, $sold['product']), 'customer' => $customer];
}

afterEach(function () {
    unset($GLOBALS['feedbackOpsSold']);
});

describe('the events', function () {
    it('records a question answered, with its ids, and gives its message the question and the answer', function () {
        ['product' => $product] = Fb::sold();
        $sara = Fx::customer();
        $question = feedbackOpsAsk($sara, $product);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));

        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($question, 'Yes, from 60 cm.'));

        $events = feedbackOpsEvents(QuestionAnswered::class);
        $message = app(FeedbackApi::class)->questionForMessage(strtoupper($question));

        expect(count($events))->toBe(1)
            ->and(array_intersect_key($events[0], array_flip(['questionId', 'productId', 'storeId', 'customerId'])))
            ->toEqual(['questionId' => $question, 'productId' => $product, 'storeId' => Fx::storeId('sa'), 'customerId' => $sara])
            ->and(Str::isUuid((string) $events[0]['eventId']))->toBeTrue()
            ->and([$message?->status, $message?->question, $message?->answer, $message?->reason])
            ->toBe([QuestionStatus::Answered, 'Does it fit a 60 cm cabinet?', 'Yes, from 60 cm.', null]);
    });

    it('records a question rejected or taken down only when staff wrote a reason', function () {
        ['product' => $product] = Fb::sold();
        $customers = [Fx::customer('a@example.test'), Fx::customer('b@example.test'), Fx::customer('c@example.test')];
        [$silent, $reasoned, $answered] = array_map(static fn (string $customer): string => feedbackOpsAsk($customer, $product), $customers);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));

        app(RejectQuestionHandler::class)->handle(new RejectQuestion($silent));
        app(RejectQuestionHandler::class)->handle(new RejectQuestion($reasoned, 'Asked twice.'));
        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($answered, 'Yes.'));
        app(UnpublishQuestionHandler::class)->handle(new UnpublishQuestion($answered, 'The answer was wrong.'));

        expect(array_column(feedbackOpsEvents(QuestionNotPublished::class), 'questionId'))->toBe([$reasoned, $answered])
            ->and(app(FeedbackApi::class)->questionForMessage($reasoned)?->reason)->toBe('Asked twice.')
            ->and(app(FeedbackApi::class)->questionForMessage($answered)?->status)->toBe(QuestionStatus::Unpublished);
    });

    it('records a review rejected or taken down only with a reason, one by one or many at once, never an approval', function () {
        $silent = feedbackOpsReview('a@example.test');
        $reasoned = feedbackOpsReview('b@example.test');
        $approved = feedbackOpsReview('c@example.test');
        $bulk = feedbackOpsReview('d@example.test');
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

        app(RejectReviewHandler::class)->handle(new RejectReview($silent['review']));
        app(RejectReviewHandler::class)->handle(new RejectReview($reasoned['review'], 'Not about the product.'));
        app(ApproveReviewHandler::class)->handle(new ApproveReview($approved['review']));
        app(UnpublishReviewHandler::class)->handle(new UnpublishReview($approved['review'], 'Personal details.'));
        app(BulkDecideReviewsHandler::class)->handle(new BulkDecideReviews(Fx::storeId('sa'), 'reject', [$bulk['review']], 'Spam.'));

        $events = feedbackOpsEvents(ReviewNotPublished::class);
        $message = app(FeedbackApi::class)->reviewForMessage($reasoned['review']);

        expect(array_column($events, 'reviewId'))->toBe([$reasoned['review'], $approved['review'], $bulk['review']])
            ->and(array_intersect_key($events[0], array_flip(['productId', 'storeId', 'customerId'])))
            ->toEqual(['productId' => $reasoned['product'], 'storeId' => Fx::storeId('sa'), 'customerId' => $reasoned['customer']])
            ->and([$message?->status, $message?->reason, $message?->rating, $message?->customerId])
            ->toBe([ReviewStatus::Rejected, 'Not about the product.', 5, $reasoned['customer']]);
    });

    it('sends nothing for a reason of spaces only or a review taken down without one, and one each for many questions at once', function () {
        $review = feedbackOpsReview('a@example.test');
        ['product' => $product] = $GLOBALS['feedbackOpsSold'];
        $spaces = feedbackOpsAsk(Fx::customer('b@example.test'), $product);
        $bulk = [feedbackOpsAsk(Fx::customer('c@example.test'), $product), feedbackOpsAsk(Fx::customer('d@example.test'), $product)];
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE, FeedbackPermissions::QUESTION_ANSWER], ['sa']));

        app(ApproveReviewHandler::class)->handle(new ApproveReview($review['review']));
        app(UnpublishReviewHandler::class)->handle(new UnpublishReview($review['review']));
        app(RejectQuestionHandler::class)->handle(new RejectQuestion($spaces, '   '));
        app(BulkDecideQuestionsHandler::class)->handle(new BulkDecideQuestions(Fx::storeId('sa'), 'reject', $bulk, 'Duplicate.'));

        expect(feedbackOpsEvents(ReviewNotPublished::class))->toBe([])
            ->and(array_column(feedbackOpsEvents(QuestionNotPublished::class), 'questionId'))->toEqualCanonicalizing($bulk);
    });

    it('records nothing when the change is refused', function () {
        $review = feedbackOpsReview();
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));
        app(RejectReviewHandler::class)->handle(new RejectReview($review['review'], 'Off topic.'));

        // A review already rejected cannot be rejected again.
        expect(fn () => app(RejectReviewHandler::class)->handle(new RejectReview($review['review'], 'Again.')))->toThrow(InvalidModeration::class)
            ->and(count(feedbackOpsEvents(ReviewNotPublished::class)))->toBe(1);
    });
});

describe('the reads', function () {
    it('answers a review or question that is not one as none', function () {
        expect(app(FeedbackApi::class)->reviewForMessage('nope'))->toBeNull()
            ->and(app(FeedbackApi::class)->reviewForMessage(strtolower((string) Str::ulid())))->toBeNull()
            ->and(app(FeedbackApi::class)->questionForMessage(strtolower((string) Str::ulid())))->toBeNull();
    });

    it('counts the customers who saved each product in a store, most saved first, a page at a time', function () {
        $products = [Fb::sold(['sa', 'ae'])['product'], Fb::sold()['product'], Fb::sold()['product']];
        $customers = array_map(static fn (int $n): string => Fx::customer("c{$n}@example.test"), range(1, 3));
        // The first saved by three, the second by one, the third by two; and one in another store.
        $saves = [[0, 0], [0, 1], [0, 2], [1, 0], [2, 1], [2, 2]];

        foreach ($saves as [$product, $customer]) {
            DB::table('feedback.wishlist_items')->insert(['customer_id' => $customers[$customer], 'product_id' => $products[$product], 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
        }

        DB::table('feedback.wishlist_items')->insert(['customer_id' => $customers[0], 'product_id' => $products[1], 'store_id' => Fx::storeId('ae'), 'saved_at' => CarbonImmutable::now()]);

        $api = app(FeedbackApi::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));
        $first = $api->favouriteCounts($sa, null, 2);
        $second = $api->favouriteCounts($sa, $first->next, 2);
        $rows = static fn (array $page): array => array_map(static fn (FavouriteCountDto $row): array => [$row->productId, $row->customers], $page);

        expect($rows($first->rows))->toBe([[$products[0], 3], [$products[2], 2]])
            ->and($rows($second->rows))->toBe([[$products[1], 1]])
            ->and($second->next)->toBeNull()
            ->and($rows($api->favouriteCounts($sa, 'not-a-cursor', 1)->rows))->toBe([[$products[0], 3]])
            ->and($rows($api->favouriteCounts(StoreId::fromString(Fx::storeId('ae')))->rows))->toBe([[$products[1], 1]]);
    });

    it('counts a range\'s new reviews and questions, and the questions waiting now', function () {
        $review = feedbackOpsReview();
        ['product' => $product] = $GLOBALS['feedbackOpsSold'];
        $waiting = feedbackOpsAsk(Fx::customer('q1@example.test'), $product);
        $answered = feedbackOpsAsk(Fx::customer('q2@example.test'), $product);
        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));
        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($answered, 'Yes.'));
        // A question asked yesterday, still waiting.
        DB::table('feedback.questions')->where('id', $waiting)->update(['created_at' => CarbonImmutable::now()->subDay()]);

        $api = app(FeedbackApi::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));
        $today = $api->counts($sa, CarbonImmutable::now()->subHours(2), CarbonImmutable::now()->addMinute());
        $reversed = $api->counts($sa, CarbonImmutable::now(), CarbonImmutable::now()->subDay());
        $elsewhere = $api->counts(StoreId::fromString(Fx::storeId('ae')), CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addMinute());

        expect($review['review'])->not->toBe('')
            ->and([$today->newReviews, $today->newQuestions, $today->waitingQuestions])->toBe([1, 1, 1])
            ->and([$reversed->newReviews, $reversed->newQuestions, $reversed->waitingQuestions])->toBe([0, 0, 1])
            ->and([$elsewhere->newReviews, $elsewhere->newQuestions, $elsewhere->waitingQuestions])->toBe([0, 0, 0]);
    });
});
