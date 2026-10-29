<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Delivery;

use Modules\Ops\Public\Enums\MessageKind;

/**
 * What staff write to many customers and Ops sends in batches (ops.md §1.7, §1.8): an offer notice, or
 * a staff member's message. Both go by the bell and the email, as their writer ticked.
 */
enum Broadcast: string
{
    case Notice = 'notice';
    case StaffMessage = 'staff_message';

    public function kind(): MessageKind
    {
        return match ($this) {
            self::Notice => MessageKind::OfferNotice,
            self::StaffMessage => MessageKind::StaffMessage,
        };
    }
}
