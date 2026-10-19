<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\CatalogItem;
use Modules\Feedback\Application\Command\AnswerQuestion\AnswerQuestion;
use Modules\Feedback\Application\Command\AnswerQuestion\AnswerQuestionHandler;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestion;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestionHandler;
use Modules\Feedback\Application\Command\CorrectAnswer\CorrectAnswer;
use Modules\Feedback\Application\Command\CorrectAnswer\CorrectAnswerHandler;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestion;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestionHandler;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestion;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestionHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\QuestionNotFound;
use Modules\Feedback\Domain\Exception\TooManyWaitingQuestions;
use Modules\Feedback\Infrastructure\Eloquent\FeedbackCatalogUsage;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Product questions (feedback.md §1.3, §4, §5, §7; answers 16, 18, 20): asked in a store by a signed-in
| customer — at most five waiting in all — answered, corrected, rejected or unpublished by the staff of
| the store asked in, each audited; global once answered.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function ask(string $customerId, string $productId, string $body = 'Does it fit a 60 cm cabinet?', string $storeCode = 'sa', string $locale = 'en'): string
{
    Fx::actAsCustomer($customerId);

    return app(AskQuestionHandler::class)->handle(new AskQuestion(Fx::storeId($storeCode), $productId, $body, $locale));
}

/**
 * @param  list<string>  $stores
 */
function answerer(array $stores = ['sa']): string
{
    $staff = Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

/**
 * @return array<string, mixed>
 */
function questionRow(string $id): array
{
    return (array) (DB::table('feedback.questions')->where('id', $id)->first() ?? throw new RuntimeException('No such question.'));
}

/**
 * @return array<string, mixed>
 */
function questionAudit(string $action): array
{
    return json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->orderByDesc('id')->value('changes'), true);
}

describe('asking', function () {
    it('takes a signed-in customer\'s question in a store that sells the product, waiting, Arabic digits as 0-9', function () {
        ['product' => $product] = Fb::sold();
        $sara = Fx::customer();

        $row = questionRow(ask($sara, $product, '  هل يناسب خزانة ٦٠ سم؟  ', locale: 'ar'));

        expect([$row['status'], $row['body'], $row['locale'], $row['store_id'], $row['customer_id'], $row['answer']])
            ->toBe(['PENDING', 'هل يناسب خزانة 60 سم؟', 'ar', Fx::storeId('sa'), $sara, null]);
    });

    it('takes at most five waiting in all stores, and more once one is answered', function () {
        ['product' => $product] = Fb::sold(['sa', 'ae']);
        $sara = Fx::customer();
        $first = ask($sara, $product);

        foreach (range(2, 5) as $n) {
            ask($sara, $product, "Question {$n}", $n % 2 === 0 ? 'ae' : 'sa');
        }

        expect(fn () => ask($sara, $product, 'A sixth', 'ae'))->toThrow(TooManyWaitingQuestions::class)
            ->and(DB::table('feedback.questions')->count())->toBe(5);

        answerer();
        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($first, 'Yes.'));

        expect(ask($sara, $product, 'A sixth, now'))->toBeString()
            // Another customer's waiting questions never count.
            ->and(ask(Fx::customer('omar@example.test'), $product))->toBeString();
    });

    it('refuses what a question cannot be, naming the field', function (Closure $ask, string $attribute) {
        ['product' => $product] = Fb::sold();
        $sara = Fx::customer();

        expect(Fb::thrown(fn () => $ask($sara, $product), InvalidFeedbackAttribute::class)->attribute)->toBe($attribute)
            ->and(DB::table('feedback.questions')->count())->toBe(0);
    })->with([
        'no text' => [fn (string $c, string $p) => ask($c, $p, '   '), 'body'],
        'too much text' => [fn (string $c, string $p) => ask($c, $p, str_repeat('س', 1001)), 'body'],
        'another language' => [fn (string $c, string $p) => ask($c, $p, locale: 'fr'), 'locale'],
        'a store that does not sell it' => [fn (string $c, string $p) => ask($c, $p, storeCode: 'eg'), 'product'],
        'a product that is not one' => [fn (string $c) => ask($c, 'not-a-product'), 'product'],
        'a store that is not one' => [function (string $c, string $p) {
            Fx::actAsCustomer($c);
            app(AskQuestionHandler::class)->handle(new AskQuestion('not-a-store', $p, 'Hello', 'en'));
        }, 'store'],
    ]);

    it('takes 1,000 characters, counted as characters', function () {
        ['product' => $product] = Fb::sold();

        expect(mb_strlen((string) questionRow(ask(Fx::customer(), $product, str_repeat('س', 1000)))['body']))->toBe(1000);
    });

    it('is for a signed-in customer only, in an open store', function () {
        ['product' => $product] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();
        $eg = Fx::storeId('eg');

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(AskQuestionHandler::class)->handle(new AskQuestion(Fx::storeId('sa'), $product, 'Hello', 'en')))->toThrow(Unauthorized::class);

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsCustomer($sara);
        expect(Fb::thrown(fn () => app(AskQuestionHandler::class)->handle(new AskQuestion($eg, $product, 'Hello', 'en')), InvalidFeedbackAttribute::class)->attribute)->toBe('store');
    });
});

describe('answering', function () {
    it('answers a waiting question, recording who and when, audited', function () {
        ['product' => $product] = Fb::sold();
        $id = ask(Fx::customer(), $product);
        $staff = answerer();

        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion(strtoupper($id), "  Yes — it fits\r\n60 cm and up.  "));
        $row = questionRow($id);

        expect([$row['status'], $row['answer'], $row['answered_by']])->toBe(['ANSWERED', "Yes — it fits\n60 cm and up.", $staff])
            ->and($row['answered_at'])->not->toBeNull()
            ->and(questionAudit('feedback.question.answered'))->toEqual(['status' => ['PENDING', 'ANSWERED'], 'answer' => [null, "Yes — it fits\n60 cm and up."]]);
    });

    it('corrects an answer: the text only, who answered and when kept, audited from old to new', function () {
        ['product' => $product] = Fb::sold();
        $id = ask(Fx::customer(), $product);
        $first = answerer();
        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.'));
        $answeredAt = questionRow($id)['answered_at'];
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        answerer();

        app(CorrectAnswerHandler::class)->handle(new CorrectAnswer($id, 'Yes, from 60 cm.'));
        $row = questionRow($id);

        expect([$row['answer'], $row['answered_by'], $row['answered_at']])->toBe(['Yes, from 60 cm.', $first, $answeredAt])
            ->and(questionAudit('feedback.question.answer_changed'))->toBe(['answer' => ['Yes.', 'Yes, from 60 cm.']]);

        // The same text again changes and records nothing.
        app(CorrectAnswerHandler::class)->handle(new CorrectAnswer($id, 'Yes, from 60 cm.'));
        expect(DB::table('platform.audit_entries')->where('action', 'feedback.question.answer_changed')->count())->toBe(1);
    });

    it('rejects a waiting question with or without a reason', function () {
        ['product' => $product] = Fb::sold();
        $withReason = ask(Fx::customer(), $product);
        $without = ask(Fx::customer('omar@example.test'), $product);
        answerer();

        app(RejectQuestionHandler::class)->handle(new RejectQuestion($withReason, '  Not about the product.  '));
        app(RejectQuestionHandler::class)->handle(new RejectQuestion($without));

        expect([questionRow($withReason)['status'], questionRow($withReason)['reason']])->toBe(['REJECTED', 'Not about the product.'])
            ->and([questionRow($without)['status'], questionRow($without)['reason']])->toBe(['REJECTED', null])
            ->and(questionAudit('feedback.question.rejected'))->toEqual(['status' => ['PENDING', 'REJECTED']]);
    });

    it('unpublishes an answered question: its answer kept for the customer, audited', function () {
        ['product' => $product] = Fb::sold();
        $id = ask(Fx::customer(), $product);
        answerer();
        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.'));

        app(UnpublishQuestionHandler::class)->handle(new UnpublishQuestion($id, 'Asked by the customer'));

        expect([questionRow($id)['status'], questionRow($id)['answer'], questionRow($id)['reason']])->toBe(['UNPUBLISHED', 'Yes.', 'Asked by the customer'])
            ->and(questionAudit('feedback.question.unpublished'))->toEqual(['status' => ['ANSWERED', 'UNPUBLISHED'], 'reason' => [null, 'Asked by the customer']]);
    });

    it('refuses a move its state does not allow, and changes nothing', function (Closure $setUp, Closure $move, string $status) {
        ['product' => $product] = Fb::sold();
        $id = ask(Fx::customer(), $product);
        answerer();
        $setUp($id);

        expect(fn () => $move($id))->toThrow(InvalidModeration::class)
            ->and(questionRow($id)['status'])->toBe($status);
    })->with([
        'answering twice' => [fn (string $id) => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.')), fn (string $id) => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Again.')), 'ANSWERED'],
        'answering a rejected one' => [fn (string $id) => app(RejectQuestionHandler::class)->handle(new RejectQuestion($id)), fn (string $id) => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.')), 'REJECTED'],
        'correcting an unanswered one' => [fn () => null, fn (string $id) => app(CorrectAnswerHandler::class)->handle(new CorrectAnswer($id, 'Yes.')), 'PENDING'],
        'unpublishing an unanswered one' => [fn () => null, fn (string $id) => app(UnpublishQuestionHandler::class)->handle(new UnpublishQuestion($id)), 'PENDING'],
        'rejecting an answered one' => [fn (string $id) => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.')), fn (string $id) => app(RejectQuestionHandler::class)->handle(new RejectQuestion($id)), 'ANSWERED'],
        'correcting an unpublished one' => [function (string $id) {
            app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.'));
            app(UnpublishQuestionHandler::class)->handle(new UnpublishQuestion($id));
        }, fn (string $id) => app(CorrectAnswerHandler::class)->handle(new CorrectAnswer($id, 'No.')), 'UNPUBLISHED'],
    ]);

    it('refuses an empty or too long answer, and a reason too long', function () {
        ['product' => $product] = Fb::sold();
        $id = ask(Fx::customer(), $product);
        answerer();

        expect(Fb::thrown(fn () => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, '  ')), InvalidFeedbackAttribute::class)->attribute)->toBe('answer')
            ->and(Fb::thrown(fn () => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, str_repeat('a', 2001))), InvalidFeedbackAttribute::class)->attribute)->toBe('answer')
            ->and(Fb::thrown(fn () => app(RejectQuestionHandler::class)->handle(new RejectQuestion($id, str_repeat('a', 501))), InvalidFeedbackAttribute::class)->attribute)->toBe('reason')
            ->and(questionRow($id)['status'])->toBe('PENDING');
    });
});

describe('who answers', function () {
    it('is the staff of the store it was asked in: another store\'s question is not found, as an unknown one', function (Closure $move) {
        ['product' => $product] = Fb::sold(['ae']);
        $uae = ask(Fx::customer(), $product, storeCode: 'ae');
        answerer(['sa']);

        expect(fn () => $move($uae))->toThrow(QuestionNotFound::class)
            ->and(fn () => $move(strtolower((string) Str::ulid())))->toThrow(QuestionNotFound::class)
            ->and(fn () => $move('not-an-id'))->toThrow(QuestionNotFound::class);

        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['*']));
        expect(fn () => $move($uae))->toThrow(Unauthorized::class);
    })->with([
        'answering' => [fn (string $id) => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($id, 'Yes.'))],
        'correcting' => [fn (string $id) => app(CorrectAnswerHandler::class)->handle(new CorrectAnswer($id, 'Yes.'))],
        'rejecting' => [fn (string $id) => app(RejectQuestionHandler::class)->handle(new RejectQuestion($id))],
        'unpublishing' => [fn (string $id) => app(UnpublishQuestionHandler::class)->handle(new UnpublishQuestion($id))],
    ]);

    it('keeps a switched-off store\'s questions for Super Admins alone', function () {
        ['product' => $product] = Fb::sold(['eg']);
        $egypt = ask(Fx::customer(), $product, storeCode: 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        answerer(['*']);

        expect(fn () => app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($egypt, 'Yes.')))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(AnswerQuestionHandler::class)->handle(new AnswerQuestion($egypt, 'Yes.'));
        expect(questionRow($egypt)['status'])->toBe('ANSWERED');
    });
});

it('lets Catalog refuse to delete a product a question is about', function () {
    ['product' => $product] = Fb::sold();
    $id = ask(Fx::customer(), $product);

    expect(array_map(static fn ($use): array => [$use->kind, $use->id], app(FeedbackCatalogUsage::class)->usesOf(CatalogItem::Product, [$product])))->toBe([['question', $id]])
        ->and(app(FeedbackCatalogUsage::class)->usesOf(CatalogItem::Variant, [$product]))->toBe([]);
});
