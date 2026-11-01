<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Newsletter;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Ops\Application\Newsletter\NewsletterQueue;
use Modules\Ops\Infrastructure\Queue\RemoveNewsletterContactJob;
use Modules\Ops\Infrastructure\Queue\SyncSubscriberJob;
use Modules\Ops\Infrastructure\Queue\WithdrawMarketingConsentJob;
use Shared\Domain\ValueObject\StoreId;

/** `NewsletterQueue` on Laravel's queue (ops.md §1.9). */
final readonly class QueuedNewsletter implements NewsletterQueue
{
    public function __construct(
        private Dispatcher $jobs,
    ) {}

    public function sync(string $subscriberId): void
    {
        $this->jobs->dispatch(new SyncSubscriberJob(strtolower($subscriberId)));
    }

    public function remove(string $email, StoreId $store, string $locale, bool $forget = false): void
    {
        $this->jobs->dispatch(new RemoveNewsletterContactJob($email, $store->value, $locale, $forget));
    }

    public function withdrawConsent(string $customerId, MarketingConsentSource $source): void
    {
        $this->jobs->dispatch(new WithdrawMarketingConsentJob(strtolower($customerId), $source));
    }

    public function withdrawConsentNow(string $customerId, MarketingConsentSource $source): void
    {
        $this->jobs->dispatchSync(new WithdrawMarketingConsentJob(strtolower($customerId), $source));
    }
}
