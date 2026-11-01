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
}
