<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Modules\Payments\Application\Gateway\PaymentAnswer;

/**
 * What came of asking: a payment's answer, a session that was never charged, or - both empty - nothing we can
 * go on: "we do not know" (rule 9).
 */
final readonly class AskResult
{
    public function __construct(
        public ?PaymentAnswer $payment = null,
        public bool $sessionUnused = false,
    ) {}

    /** Something that will not change: a payment that went through or was declined, or a session never charged. */
    public function isFinal(): bool
    {
        return $this->sessionUnused || ($this->payment !== null && AttemptSettlement::isFinal($this->payment));
    }
}
