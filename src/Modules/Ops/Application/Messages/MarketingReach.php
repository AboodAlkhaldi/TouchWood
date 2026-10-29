<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Enums\CustomerStatus;

/**
 * Who an email may reach (ops.md §1.3).
 *
 * - **Marketing** (a notice, a message to many, the newsletter): only a customer who agreed to offers
 *   and news, whose email is verified, who is not blocked and has no deletion pending (owner,
 *   2026-10-28, §9.3 #14).
 * - **Service** (a message to one customer): every account that is not anonymized - consent is not
 *   asked (§9.3 #2).
 *
 * The bell is our own site: a marketing bell entry goes to every customer (owner, R4-6).
 */
final class MarketingReach
{
    public static function mayEmailOffers(CustomerDto $customer): bool
    {
        return $customer->marketingConsent
            && $customer->emailVerified
            && $customer->status === CustomerStatus::Active
            && $customer->deletionScheduledFor === null
            && ! $customer->anonymized;
    }

    public static function mayEmailService(CustomerDto $customer): bool
    {
        return ! $customer->anonymized;
    }
}
