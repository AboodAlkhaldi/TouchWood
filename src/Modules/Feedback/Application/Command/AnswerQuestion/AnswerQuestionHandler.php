<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\AnswerQuestion;

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
 * Answers a waiting question (feedback.md §1.3): it shows in every store selling the product, signed
 * "{store}'s team" — never the staff member's name, though they are recorded. By the staff of the store it was asked in; a question of a store not covered is
 * not found (§7). The row locked first; a state that does not allow it is `InvalidModeration`.
 */
final readonly class AnswerQuestionHandler
{
    public const string PERMISSION = FeedbackPermissions::QUESTION_ANSWER;

    public function __construct(
        private StaffChange $change,
        private Questions $questions,
    ) {}

    /**
     * @throws Unauthorized|QuestionNotFound|InvalidModeration|InvalidFeedbackAttribute
     */
    public function handle(AnswerQuestion $command): void
    {
        [$store, $staffId] = $this->change->authorize(self::PERMISSION, fn () => $this->questions->storeOf($command->questionId), static fn (): QuestionNotFound => new QuestionNotFound);
        $answer = FeedbackText::of($command->answer, 'answer', 2000);
        $locale = $command->locale === null ? null : FeedbackText::locale($command->locale);

        $this->change->run(function () use ($store, $command, $answer, $staffId, $locale): array {
            $question = $this->questions->lock($store, $command->questionId) ?? throw new QuestionNotFound;

            if ($question->status !== QuestionStatus::Pending) {
                throw new InvalidModeration;
            }

            $now = CarbonImmutable::now();
            $this->questions->answer($question, $answer, $staffId, $now);
            $from = ['status' => $question->status->value, 'answer' => null];
            $to = ['status' => QuestionStatus::Answered->value, 'answer' => $answer];

            // The question's language as staff set it (§2.4 B3).
            if ($locale !== null && $locale !== $question->locale) {
                $this->questions->setLocale($question, $locale, $now);
                [$from['locale'], $to['locale']] = [$question->locale, $locale];
            }

            return [null, [FeedbackAudit::entry('question', 'answered', $question->id, $store->value, $from, $to)]];
        });
    }
}
