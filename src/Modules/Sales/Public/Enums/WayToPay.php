<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * How an order is paid (sales.md §1.9): online through Payments (stage 7; individuals only), by bank
 * transfer to the store's account (companies only), or "staff will contact you" (both). No cash on
 * delivery in any store.
 */
enum WayToPay: string
{
    case Online = 'ONLINE';
    case BankTransfer = 'BANK_TRANSFER';
    case StaffContact = 'STAFF_CONTACT';
}
