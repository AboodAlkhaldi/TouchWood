<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\WithdrawMarketingConsent;

use Modules\Access\Public\Enums\MarketingConsentSource;

/**
 * Ops received an unsubscribe for this customer (amendment 79): an email's link or the newsletter
 * provider. Withdrawn at once, everywhere.
 */
final readonly class WithdrawMarketingConsent
{
    public function __construct(
        public string $customerId,
        public MarketingConsentSource $source,
    ) {}
}
