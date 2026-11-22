<?php

declare(strict_types=1);

namespace Modules\Ops\Public\Enums;

/**
 * How a message reaches someone (ops.md §1.1): an entry in their inbox (the bell), an email through
 * the email adapter, a code or critical alert through WhatsApp (ops.md §9.8; access.md amendment 98).
 *
 * `Sms` is kept for the rows written before WhatsApp replaced it: no SMS provider is ever built, and every new code
 * or alert is recorded as `Whatsapp`, whatever provider (`log` in development) carried it.
 */
enum MessageChannel: string
{
    case Bell = 'BELL';
    case Email = 'EMAIL';
    case Sms = 'SMS';
    case Whatsapp = 'WHATSAPP';
}
