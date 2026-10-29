<?php

declare(strict_types=1);

namespace Modules\Ops\Public\Enums;

/**
 * How a message reaches someone (ops.md §1.1): an entry in their inbox (the bell), an email through
 * the email adapter, an SMS through the SMS adapter.
 */
enum MessageChannel: string
{
    case Bell = 'BELL';
    case Email = 'EMAIL';
    case Sms = 'SMS';
}
