<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Enums;

/**
 * A store's gateway in test or live (payments.md §1.6): TEST takes no real money.
 */
enum PaymentMode: string
{
    case Test = 'TEST';
    case Live = 'LIVE';
}
