<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Enums;

/** What an attempt pays for (payments.md §2.1): a checkout try, or a staff request on an order. */
enum PaymentOwnerKind: string
{
    case Try = 'TRY';
    case Request = 'REQUEST';
}
