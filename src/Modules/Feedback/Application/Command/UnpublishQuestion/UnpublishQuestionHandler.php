<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\UnpublishQuestion;

use Carbon\CarbonImmutable;
use Modules\Feedback\Application\FeedbackAudit;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\StaffChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\QuestionNotFound;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Feedback\Domain\Repository\Questions;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Shared\Application\Unauthorized;

/**
 * Unpublishes an answered question (feedback.md §1.3, answer 18): it leaves every shop page; the
 * customer still sees it, and the reason if one was written. By the staff of the store it was asked in; a question of a store not covered is
 * not found (§7). The row locked first; a state that does not allow it is `InvalidModeration`.
 */
final readonly class UnpublishQuestionHandler
{
    public const string PERMISSION = FeedbackPermissions::QUESTION_ANSWER;

    public function __construct(
        private StaffChange $change,
        private Questions $questions,
    ) {}

    /**
     * @throws Unauthorized|QuestionNotFound|InvalidModeration|InvalidFeedbackAttribute
     */
    public function handle(UnpublishQuestion $command): void
    {
        [$store] = $this->change->authorize(self::PERMISSION, fn () => $this->questions->storeOf($command->questionId), static fn (): QuestionNotFound => new QuestionNotFound);
        $reason = FeedbackText::reason($command->reason);

        $this->change->run(function () use ($store, $command, $reason): array {
            $question = $this->questions->lock($store, $command->questionId) ?? throw new QuestionNotFound;

            if ($question->status !== QuestionStatus::Answered) {
                throw new InvalidModeration;
            }

            $now = CarbonImmutable::now();
            $this->questions->unpublish($question, $reason, $now);
            $from = ['status' => $question->status->value, 'reason' => null];
            $to = ['status' => QuestionStatus::Unpublished->value, 'reason' => $reason];

            return [null, [FeedbackAudit::entry('question', 'unpublished', $question->id, $store->value, $from, $to)]];
        });
    }
}
