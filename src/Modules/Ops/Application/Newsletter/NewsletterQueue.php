<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Newsletter;

use Modules\Access\Public\Enums\MarketingConsentSource;
use Shared\Domain\ValueObject\StoreId;

/**
 * The newsletter's work that waits for the queue (ops.md §1.9): the provider is called from a job,
 * never from a person's request, and retried there. Called inside the transaction that made the
 * change: the queue's connection waits for the commit.
 */
interface NewsletterQueue
{
    /** Brings the provider in line with the row: added, moved, or taken out. */
    public function sync(string $subscriberId): void;

    /**
     * Takes an email off the store's list of that language - its row is gone. `$forget` deletes the
     * contact itself too, when no other row still holds the email (an anonymized account, §6).
     */
    public function remove(string $email, StoreId $store, string $locale, bool $forget = false): void;

    /** The customer unsubscribed - by a link, or at the provider: their consent is withdrawn, as the system (§1.3). */
    public function withdrawConsent(string $customerId, MarketingConsentSource $source): void;

    /**
     * The same within the request, for a person pressing an unsubscribe button (§1.3: "at once"): Access
     * lets only the system withdraw consent, and a job run here is the system, on nobody's account.
     */
    public function withdrawConsentNow(string $customerId, MarketingConsentSource $source): void;
}
