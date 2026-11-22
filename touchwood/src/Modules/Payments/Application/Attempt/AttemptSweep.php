<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Notices;

/**
 * The one backstop (payments.md §1.1 rule 9, §9.6 #4): once an hour a single look for a check or a notice whose job
 * was lost. With none, it ends at once.
 */
final readonly class AttemptSweep
{
    private const int LATE_MINUTES = 5;

    /** The checks' own limit: at most 60 attempts a minute across the system (§1.8). */
    private const int MOST = 60;

    public function __construct(
        private Attempts $attempts,
        private Notices $notices,
        private PaymentJobs $jobs,
    ) {}

    public function sweep(): void
    {
        $before = CarbonImmutable::now()->subMinutes(self::LATE_MINUTES)->toDateTimeImmutable();

        foreach ($this->attempts->overdue($before, self::MOST) as $id) {
            $this->jobs->checkAttempt($id, CarbonImmutable::now()->toDateTimeImmutable());
        }

        foreach ($this->notices->unhandled(CarbonImmutable::now()->subDay()->toDateTimeImmutable(), $before, self::MOST) as $id) {
            $this->jobs->handleNotice($id);
        }
    }
}
