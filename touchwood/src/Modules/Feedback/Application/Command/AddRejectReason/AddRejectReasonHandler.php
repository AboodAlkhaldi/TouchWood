<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\AddRejectReason;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Feedback\Application\FeedbackAudit;
use Modules\Feedback\Application\Reason\ReasonInputs;
use Modules\Feedback\Application\Reason\ReasonsChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Shared\Application\Unauthorized;

/** Adds a ready reason at the end of the list (feedback.md §2.4 B4). Admins of every store; audited. */
final readonly class AddRejectReasonHandler
{
    public const string PERMISSION = ReasonsChange::PERMISSION;

    public function __construct(
        private ReasonsChange $change,
        private RejectReasons $reasons,
    ) {}

    /**
     * @return string the reason's id
     *
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(AddRejectReason $command): string
    {
        $this->change->authorize();
        [$textAr, $textEn] = ReasonInputs::words($command->textAr, $command->textEn);

        return $this->change->run(function () use ($textAr, $textEn): array {
            $id = strtolower((string) Str::ulid());
            $position = $this->reasons->add($id, $textAr, $textEn, CarbonImmutable::now());

            return [$id, [FeedbackAudit::entry('reject_reason', 'added', $id, null, [], ['text_ar' => $textAr, 'text_en' => $textEn, 'position' => (string) $position])]];
        });
    }
}
