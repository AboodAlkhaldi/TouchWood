<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\MarketingConsentSource;

/**
 * A customer unsubscribed - by a marketing email's link, or in a newsletter at the provider - so Access
 * withdraws their consent, everywhere (ops.md §1.3, §1.9; access.md amendment 79). Run as the system,
 * which only a job is. Withdrawing what is already off changes nothing.
 */
final class WithdrawMarketingConsentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public string $customerId,
        public MarketingConsentSource $source,
    ) {}

    public function handle(AccessApi $access): void
    {
        $access->withdrawMarketingConsent($this->customerId, $this->source);
    }
}
