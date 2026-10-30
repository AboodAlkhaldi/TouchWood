<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A country that is not on the SMS countries list (access.md amendment 90).
 */
final class SmsCountryNotFound extends OpsError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("The country \"{$countryCode}\" is not on the SMS countries list.");
    }

    public function type(): string
    {
        return 'ops.sms_country_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode];
    }
}
