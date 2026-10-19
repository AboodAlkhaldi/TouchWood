<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Feedback\Domain\Model\Question;
use Modules\Feedback\Domain\Repository\Questions;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/** `Questions` in SQL, no Eloquent model (feedback.md §1.6). */
final readonly class DatabaseQuestions implements Questions
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function storeOf(string $questionId): ?StoreId
    {
        $store = Ulids::valid($questionId) ? $this->db->table('feedback.questions')->where('id', strtolower($questionId))->value('store_id') : null;

        return $store === null ? null : StoreId::fromString((string) $store);
    }

    public function lock(StoreId $store, string $questionId): ?Question
    {
        if (! Ulids::valid($questionId)) {
            return null;
        }

        $row = $this->db->table('feedback.questions')->where('store_id', $store->value)->where('id', strtolower($questionId))->lockForUpdate()->first();

        return $row instanceof stdClass ? self::toQuestion($row) : null;
    }

    public function waiting(string $customerId): int
    {
        return $this->db->table('feedback.questions')->where('customer_id', $customerId)->where('status', QuestionStatus::Pending->value)->count();
    }

    public function create(string $questionId, string $productId, StoreId $store, string $customerId, string $body, string $locale, DateTimeImmutable $at): void
    {
        $this->db->table('feedback.questions')->insert([
            'id' => $questionId,
            'product_id' => $productId,
            'store_id' => $store->value,
            'customer_id' => $customerId,
            'body' => $body,
            'locale' => $locale,
            'status' => QuestionStatus::Pending->value,
            'created_at' => Moment::of($at),
            'updated_at' => Moment::of($at),
        ]);
    }

    public function answer(Question $question, string $answer, string $staffId, DateTimeImmutable $at): void
    {
        $this->set($question, ['status' => QuestionStatus::Answered->value, 'answer' => $answer, 'answered_by' => $staffId, 'answered_at' => Moment::of($at)], $at);
    }

    public function correct(Question $question, string $answer, DateTimeImmutable $at): void
    {
        $this->set($question, ['answer' => $answer], $at);
    }

    public function reject(Question $question, ?string $reason, DateTimeImmutable $at): void
    {
        $this->set($question, ['status' => QuestionStatus::Rejected->value, 'reason' => $reason], $at);
    }

    public function unpublish(Question $question, ?string $reason, DateTimeImmutable $at): void
    {
        $this->set($question, ['status' => QuestionStatus::Unpublished->value, 'reason' => $reason], $at);
    }

    /**
     * @param  array<string, string|null>  $columns
     */
    private function set(Question $question, array $columns, DateTimeImmutable $at): void
    {
        $this->db->table('feedback.questions')->where('id', $question->id)->update([...$columns, 'updated_at' => Moment::of($at)]);
    }

    private static function toQuestion(stdClass $row): Question
    {
        return new Question(
            (string) $row->id,
            (string) $row->product_id,
            (string) $row->store_id,
            (string) $row->customer_id,
            (string) $row->body,
            (string) $row->locale,
            QuestionStatus::from((string) $row->status),
            $row->answer === null ? null : (string) $row->answer,
            $row->answered_by === null ? null : (string) $row->answered_by,
            $row->answered_at === null ? null : CarbonImmutable::parse((string) $row->answered_at),
            $row->reason === null ? null : (string) $row->reason,
        );
    }
}
