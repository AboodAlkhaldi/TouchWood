<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Queue;

use DateTimeImmutable;
use Modules\Payments\Application\Attempt\PaymentJobs;

/**
 * Sent after the commit of the transaction they belong to: Sales's own rolled-back try leaves no job behind.
 */
final class PaymentQueue implements PaymentJobs
{
    public function checkAttempt(string $attemptId, DateTimeImmutable $at): void
    {
        CheckAttemptJob::dispatch($attemptId)->delay($at)->afterCommit();
    }

    public function handleNotice(string $noticeId): void
    {
        HandleNoticeJob::dispatch($noticeId)->afterCommit();
    }
}
