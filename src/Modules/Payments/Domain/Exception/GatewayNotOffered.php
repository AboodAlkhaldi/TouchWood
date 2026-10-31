<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A gateway for a store in a country MyFatoorah is not offered in - the gateway's adapter names them (owner,
 * 2026-10-29, gap 8.1).
 */
final class GatewayNotOffered extends PaymentsError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("MyFatoorah is not offered for stores in {$this->countryCode}.");
    }

    public function type(): string
    {
        return 'payments.gateway_not_offered';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unsupported;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode];
    }
}
