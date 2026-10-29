<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Newsletter;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Ops\Application\Messages\MarketingReach;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Ops\Domain\Repository\Subscribers;
use Shared\Domain\ValueObject\StoreId;

/**
 * Customers on the newsletter's lists (ops.md §1.3, §1.9, §6), kept from Access's events by Ops'
 * listeners - the system's own upkeep, nobody asks for it.
 *
 * - **refresh**: the customer is read again and put on their **home store's** list, in their language,
 *   when they may receive offers by email (MarketingReach: agreed, email verified, not blocked, no
 *   deletion pending), and taken off otherwise. A guest row of the same email in that store becomes
 *   theirs, with what the provider already holds.
 * - **forget** (an anonymized account): the row goes, and the contact is deleted at the provider.
 * - **the provider's unsubscribe** (Brevo's webhook): a guest is taken off; a customer's consent is
 *   withdrawn through Access, everywhere - its event then takes the row off.
 */
final readonly class CustomerSubscriptions
{
    public function __construct(
        private AccessApi $access,
        private Subscribers $subscribers,
        private NewsletterQueue $queue,
        private ConnectionInterface $db,
    ) {}

    public function refresh(string $customerId): void
    {
        $customerId = strtolower($customerId);
        $customer = $this->access->customer($customerId);

        $this->db->transaction(function () use ($customerId, $customer): void {
            $row = $this->subscribers->lockByCustomer($customerId);
            $email = null;

            if ($customer !== null && MarketingReach::mayEmailOffers($customer)) {
                try {
                    $email = Subscriber::email($customer->email);
                } catch (InvalidOpsInput) {
                    $email = null;
                }
            }

            if ($customer === null || $email === null) {
                if ($row !== null) {
                    $this->drop($row);
                }

                return;
            }

            $store = StoreId::fromString(strtolower($customer->homeStoreId));
            $locale = $customer->locale === 'ar' ? 'ar' : 'en';
            $guest = $this->subscribers->lockByEmail($store, $email);

            if ($guest !== null && $guest->id !== $row?->id) {
                // The guest's row becomes the customer's: the provider already holds it.
                $this->subscribers->delete($guest->id);
            }

            $held = $row ?? $guest;
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $subscriber = new Subscriber(
                $row->id ?? strtolower((string) Str::ulid()),
                $store,
                $email,
                $locale,
                $customerId,
                null,
                $row->createdAt ?? $now,
                $row->confirmedAt ?? $now,
                $held?->brevoEmail,
                $held?->brevoLocale,
            );

            if ($row !== null && $guest !== null && $guest->id !== $row->id && $row->brevoEmail !== null && $row->brevoLocale !== null) {
                // Both were held: the customer's row keeps the guest's place, its own old one goes.
                $this->queue->remove($row->brevoEmail, $row->store, $row->brevoLocale);
            }

            $this->subscribers->save($subscriber);
            $this->queue->sync($subscriber->id);
        });
    }

    /** An anonymized account (§6): the row and the provider's contact are deleted. */
    public function forget(string $customerId): void
    {
        $this->db->transaction(function () use ($customerId): void {
            $row = $this->subscribers->lockByCustomer(strtolower($customerId));

            if ($row !== null) {
                $this->drop($row, forget: true);
            }
        });
    }

    /** Someone unsubscribed in a newsletter (Brevo's webhook): that email leaves every list. */
    public function unsubscribedAtProvider(string $email): void
    {
        $this->db->transaction(function () use ($email): void {
            foreach ($this->subscribers->withEmail($email) as $row) {
                if ($row->isGuest()) {
                    $locked = $this->subscribers->lock($row->id);

                    if ($locked !== null) {
                        $this->drop($locked);
                    }

                    continue;
                }

                $this->queue->withdrawConsent((string) $row->customerId, MarketingConsentSource::NewsletterProvider);
            }
        });
    }

    private function drop(Subscriber $row, bool $forget = false): void
    {
        $this->subscribers->delete($row->id);

        if ($row->brevoEmail !== null && $row->brevoLocale !== null) {
            $this->queue->remove($row->brevoEmail, $row->store, $row->brevoLocale, $forget);
        } elseif ($forget) {
            // Nothing held for this row, but the email may be a contact still: deleted if no row holds it.
            $this->queue->remove($row->email, $row->store, $row->locale, true);
        }
    }
}
