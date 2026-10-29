<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SetOwnMarketingConsent;

/**
 * The "offers and news" switch in the customer's own account (amendment 79).
 */
final readonly class SetOwnMarketingConsent
{
    public function __construct(
        public bool $consent,
    ) {}
}
