<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewMyQuestions;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\FeedbackReader;
use Modules\Feedback\Application\Query\MyQuestionRow;
use Modules\Feedback\Application\Query\MyQuestionsPage;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * "My questions" (feedback.md §1.3, §3): `feedback.own.view`, the signed-in customer's own only —
 * every question in every store, newest first: waiting, answered (signed by the store asked in), or
 * rejected or taken down with the reason staff wrote, the answer kept when one was given (answer 18).
 */
final readonly class ViewMyQuestionsHandler
{
    public const string PERMISSION = FeedbackPermissions::OWN_VIEW;

    public const int PAGE = 20;

    public function __construct(
        private Authorizer $authorizer,
        private ReadInputs $inputs,
        private FeedbackQueries $queries,
        private FeedbackReader $reader,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ViewMyQuestions $query): MyQuestionsPage
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->inputs->customer(self::PERMISSION);
        $locale = ReadInputs::locale($query->locale);
        $questions = $this->queries->questionsBy($customerId, ReadInputs::after($query->after), self::PAGE + 1);
        $more = count($questions) > self::PAGE;
        $questions = array_slice($questions, 0, self::PAGE);

        $byStore = [];

        foreach ($questions as $question) {
            $byStore[$question->storeId][] = $question->productId;
        }

        $cards = $this->reader->cardsByStore($locale, $byStore);
        $stores = $this->reader->stores();

        return new MyQuestionsPage(
            array_map(static fn ($question): MyQuestionRow => new MyQuestionRow(
                $question->id,
                $question->storeId,
                isset($stores[$question->storeId]) ? $stores[$question->storeId]->name->in($locale) : '',
                FeedbackReader::card($cards[$question->storeId] ?? [], $question->productId),
                $question->body,
                $question->locale,
                $question->status,
                $question->answer,
                $question->answeredAt,
                $question->reason,
                $question->createdAt,
            ), $questions),
            $more && $questions !== [] ? $questions[count($questions) - 1]->id : null,
        );
    }
}
