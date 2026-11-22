<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Domain\Error\ErrorCategory;

/**
 * Test or live switched on, or the gateway switched on, while that mode has no key and secret (owner,
 * 2026-10-29, gap 8.2 (a)).
 */
final class NoGatewayKey extends PaymentsError
{
    public function __construct(public readonly PaymentMode $mode)
    {
        parent::__construct("The {$this->mode->value} mode has no key yet.");
    }

    public function type(): string
    {
        return 'payments.no_gateway_key';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['mode' => $this->mode->value];
    }
}
