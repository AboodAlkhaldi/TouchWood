<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\ChangeRejectReason;

use Carbon\CarbonImmutable;
use Modules\Feedback\Application\FeedbackAudit;
use Modules\Feedback\Application\Reason\ReasonInputs;
use Modules\Feedback\Application\Reason\ReasonsChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\RejectReasonNotFound;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Shared\Application\Unauthorized;

/** Changes a ready reason's words (feedback.md §2.4 B4). Admins of every store; audited from old to new. */
final readonly class ChangeRejectReasonHandler
{
    public const string PERMISSION = ReasonsChange::PERMISSION;

    public function __construct(
        private ReasonsChange $change,
        private RejectReasons $reasons,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute|RejectReasonNotFound
     */
    public function handle(ChangeRejectReason $command): void
    {
        $this->change->authorize();
        $id = ReasonInputs::id($command->reasonId);
        [$textAr, $textEn] = ReasonInputs::words($command->textAr, $command->textEn);

        $this->change->run(function () use ($id, $textAr, $textEn): array {
            $reason = $this->reasons->find($id) ?? throw new RejectReasonNotFound;

            if ($reason->textAr === $textAr && $reason->textEn === $textEn) {
                return [null, []];
            }

            $this->reasons->change($id, $textAr, $textEn, CarbonImmutable::now());

            return [null, [FeedbackAudit::entry('reject_reason', 'changed', $id, null, ['text_ar' => $reason->textAr, 'text_en' => $reason->textEn], ['text_ar' => $textAr, 'text_en' => $textEn])]];
        });
    }
}
