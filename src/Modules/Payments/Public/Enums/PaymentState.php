<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Enums;

/** What the page shows, and whether Sales may release (payments.md §2.1). */
enum PaymentState: string
{
    case None = 'NONE';
    case Open = 'OPEN';
    case InProgress = 'IN_PROGRESS';
    case Unknown = 'UNKNOWN';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
}
