<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListQuestions;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\FeedbackReader;
use Modules\Feedback\Application\Query\QuestionRow;
use Modules\Feedback\Application\Query\QuestionsPage;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A store's question queue (feedback.md §1.3, §3): `feedback.question.answer` in the store, the
 * questions asked there in one state — waiting ones oldest first, any other newest first — 50 a page.
 * Askers in their public form only; who answered as the reader may be shown them.
 */
final readonly class ListQuestionsHandler
{
    public const string PERMISSION = FeedbackPermissions::QUESTION_ANSWER;

    public const int PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private ReadInputs $inputs,
        private FeedbackQueries $queries,
        private FeedbackReader $reader,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ListQuestions $query): QuestionsPage
    {
        $store = ReadInputs::store($query->storeId);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $this->inputs->staffIn(self::PERMISSION, $store);
        $status = QuestionStatus::tryFrom($query->status) ?? throw new InvalidFeedbackAttribute('status', 'not a question status');
        $locale = ReadInputs::locale($query->locale);

        $questions = $this->queries->questionsIn($store, $status, ReadInputs::after($query->after), self::PAGE + 1);
        $more = count($questions) > self::PAGE;
        $questions = array_slice($questions, 0, self::PAGE);

        $cards = $this->reader->cards($store, $locale, array_map(static fn ($question): string => $question->productId, $questions));
        $authors = $this->reader->authors(array_map(static fn ($question): string => $question->customerId, $questions));
        $staff = $this->reader->staff(array_values(array_filter(array_map(static fn ($question): ?string => $question->answeredBy, $questions))));

        return new QuestionsPage(
            array_map(static fn ($question): QuestionRow => new QuestionRow(
                $question->id,
                FeedbackReader::card($cards, $question->productId),
                FeedbackReader::author($authors, $question->customerId),
                $question->body,
                $question->locale,
                $question->status,
                $question->answer,
                $question->answeredBy === null ? null : ($staff[$question->answeredBy] ?? null),
                $question->answeredAt,
                $question->reason,
                $question->createdAt,
            ), $questions),
            $more && $questions !== [] ? $questions[count($questions) - 1]->id : null,
        );
    }
}
