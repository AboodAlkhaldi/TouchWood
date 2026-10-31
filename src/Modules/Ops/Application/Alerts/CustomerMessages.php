<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Ops\Application\Messages\MarketingReach;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * Tells a customer about a moment of theirs (ops.md §1.5, C1-C17b): an entry in their bell in the
 * event's store, in both languages, opening their page in their own; and one email in their language,
 * queued. Both channels always - the kinds of §1.5 are fixed (round 1 #3).
 *
 * **Service messages**: consent is not asked (§1.3); an anonymized account gets nothing. A store
 * switched off still sends (owner, 2026-10-29). Nothing is sent when the module says there is nothing
 * to tell any more, or names another store or customer than the event (MessageWriter).
 */
final readonly class CustomerMessages
{
    public function __construct(
        private AccessApi $access,
        private PlatformApi $platform,
        private MessageWriter $writer,
        private Inbox $inbox,
        private EventDeliveries $deliveries,
    ) {}

    public function tell(EventMessage $message, string $customerId): void
    {
        $customer = $this->access->customer($customerId);

        if ($customer !== null) {
            $this->tellCustomer($message, $customer);
        }
    }

    /** The same, for a customer already read - a page of them read at once (C14). */
    public function tellCustomer(EventMessage $message, CustomerDto $customer): void
    {
        $store = $this->platform->store(StoreId::fromString($message->storeId));

        if ($store === null || ! MarketingReach::mayEmailService($customer)) {
            return;
        }

        $ar = $this->writer->write($message, $store, 'ar', $customer->firstName, $customer->id);
        $en = $this->writer->write($message, $store, 'en', $customer->firstName, $customer->id);

        if ($ar === null || $en === null) {
            return;
        }

        if ($message->kind->hasBell()) {
            $this->inbox->add(InboxEntry::forCustomer(
                strtolower((string) Str::ulid()),
                $customer->id,
                $store->storeId(),
                $message->kind,
                $ar->title,
                $en->title,
                $ar->line,
                $en->line,
                $customer->locale === 'ar' ? $ar->link : $en->link,
                CarbonImmutable::now()->toDateTimeImmutable(),
            ));
        }

        $this->deliveries->emailCustomer($message, $customer->id);
    }
}
