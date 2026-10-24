<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RemoveRejectReason;

use Modules\Feedback\Application\Command\ChangeRejectReason\ChangeRejectReasonHandler;
use Modules\Feedback\Application\FeedbackAudit;
use Modules\Feedback\Application\Reason\ReasonInputs;
use Modules\Feedback\Application\Reason\ReasonsChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\RejectReasonNotFound;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Shared\Application\Unauthorized;

/**
 * Removes a ready reason (feedback.md §2.4 B4): those after it move up. A review or question rejected with it
 * keeps the words it was given. Admins of every store; audited.
 */
final readonly class RemoveRejectReasonHandler
{
    public const string PERMISSION = ReasonsChange::PERMISSION;

    public function __construct(
        private ReasonsChange $change,
        private RejectReasons $reasons,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute|RejectReasonNotFound
     */
    public function handle(RemoveRejectReason $command): void
    {
        $this->change->authorize();
        $id = ReasonInputs::id($command->reasonId);

        $this->change->run(function () use ($id): array {
            $reason = ChangeRejectReasonHandler::find($this->reasons->all(), $id);
            $this->reasons->remove($id);

            return [null, [FeedbackAudit::entry('reject_reason', 'removed', $id, null, ['text_ar' => $reason->textAr, 'text_en' => $reason->textEn, 'position' => (string) $reason->position], [])]];
        });
    }
}
