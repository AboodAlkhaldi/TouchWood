<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Enums;

/** Who pays, as Payments' own type (sales.md §2.4): an individual, or a company. */
enum PaymentAudience: string
{
    case Individual = 'INDIVIDUAL';
    case Company = 'COMPANY';
}
