<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Newsletter;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Repository\Subscribers;
use Shared\Domain\ValueObject\StoreId;

/**
 * Keeps the provider's lists as the rows say (ops.md §1.9: "joining, leaving, changing language"),
 * from the queue. The system's own upkeep, run by Ops' jobs - nobody asks for it.
 *
 * A row is compared with what the provider holds for it: nothing to do when they agree; otherwise what
 * it holds is taken out of its list and the row's email put in its language's list - a move - and the
 * row remembers it. Every call to the provider may be repeated, so a retry after a failure half-way
 * ends in the same place.
 */
final readonly class NewsletterSync
{
    public function __construct(
        private Subscribers $subscribers,
        private NewsletterList $list,
        private ConnectionInterface $db,
    ) {}

    /** @throws ProviderFailed for the queue to try again */
    public function sync(string $subscriberId): void
    {
        $this->db->transaction(function () use ($subscriberId): void {
            $subscriber = $this->subscribers->lock($subscriberId);

            if ($subscriber === null || $subscriber->heldAtProvider() === $subscriber->wantedAtProvider()) {
                return;
            }

            if ($subscriber->brevoEmail !== null && $subscriber->brevoLocale !== null) {
                $this->list->remove($subscriber->brevoEmail, $subscriber->store, $subscriber->brevoLocale);
            }

            if ($subscriber->isConfirmed()) {
                $this->list->add($subscriber->email, $subscriber->store, $subscriber->locale);

                // Newly on the list - a customer who agreed again, a guest's new confirmed sign-up:
                // an earlier unsubscribe in a newsletter left the provider refusing to email them.
                if ($subscriber->brevoEmail === null) {
                    $this->list->allow($subscriber->email);
                }

                $this->subscribers->save($subscriber->syncedAs($subscriber->email, $subscriber->locale));

                return;
            }

            $this->subscribers->save($subscriber->syncedAs(null, null));
        });
    }

    /**
     * A row that is gone: its email off its list, and - when asked, and no row of any store still
     * holds the email - the contact itself deleted.
     *
     * @throws ProviderFailed for the queue to try again
     */
    public function remove(string $email, StoreId $store, string $locale, bool $forget): void
    {
        // Signed up again meanwhile: the row's own sync decides, not this removal.
        $again = $this->subscribers->withEmail($email);

        foreach ($again as $row) {
            if ($row->store->value === $store->value && $row->brevoLocale === $locale) {
                return;
            }
        }

        $this->list->remove($email, $store, $locale);

        if ($forget && $again === []) {
            $this->list->forget($email);
        }
    }
}
