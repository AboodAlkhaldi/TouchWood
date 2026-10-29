<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The country is already on the SMS countries list: its provider or calling code is changed there,
 * not added twice (access.md amendment 87).
 */
final class SmsCountryTaken extends OpsError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("The country \"{$countryCode}\" is already on the SMS countries list.");
    }

    public function type(): string
    {
        return 'ops.sms_country_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode];
    }
}
