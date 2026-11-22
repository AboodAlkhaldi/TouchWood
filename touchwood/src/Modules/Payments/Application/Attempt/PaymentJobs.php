<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use DateTimeImmutable;

/**
 * The work that runs later (payments.md §1.1 rule 9): sent only once the transaction it belongs to has committed.
 */
interface PaymentJobs
{
    public function checkAttempt(string $attemptId, DateTimeImmutable $at): void;

    public function handleNotice(string $noticeId): void;
}
