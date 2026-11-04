<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use Modules\Payments\Domain\Repository\GatewayCalls;
use Modules\Payments\Domain\Repository\Notices;

/**
 * `PrunePaymentLog` (payments.md §5, §9.3 #8): notices are kept 90 days, calls a year.
 */
final readonly class PaymentLogPruning
{
    public const int NOTICE_DAYS = 90;

    public function __construct(
        private Notices $notices,
        private GatewayCalls $calls,
    ) {}

    public function prune(): void
    {
        $now = CarbonImmutable::now();
        $this->notices->deleteBefore($now->subDays(self::NOTICE_DAYS)->toDateTimeImmutable());
        $this->calls->deleteBefore($now->subYear()->toDateTimeImmutable());
    }
}
