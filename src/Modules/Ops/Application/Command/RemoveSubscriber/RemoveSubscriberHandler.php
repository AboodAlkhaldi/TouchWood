<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\RemoveSubscriber;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Newsletter\NewsletterQueue;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\SubscriberNotFound;
use Modules\Ops\Domain\Repository\Subscribers;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Takes a guest off a store's newsletter list (ops.md §3), under `ops.newsletter.manage` in its store;
 * the provider's list follows from the queue. A customer is never removed here: staff never set their
 * consent (§1.3) - the customer is not found, as is a guest of a store the reader does not manage.
 */
final readonly class RemoveSubscriberHandler
{
    public const string PERMISSION = OpsPermissions::NEWSLETTER_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Subscribers $subscribers,
        private NewsletterQueue $queue,
        private ConnectionInterface $db,
    ) {}

    /** @throws SubscriberNotFound|Unauthorized */
    public function handle(RemoveSubscriber $command): void
    {
        $stores = $this->writers->storesHolding(self::PERMISSION);
        $found = $this->subscribers->find($command->subscriberId);

        if ($found === null || ! $found->isGuest() || ! Writers::among($found->store, $stores)) {
            throw new SubscriberNotFound;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($found->store));

        $this->db->transaction(function () use ($found): void {
            $subscriber = $this->subscribers->lock($found->id) ?? throw new SubscriberNotFound;
            $this->subscribers->delete($subscriber->id);

            if ($subscriber->brevoEmail !== null && $subscriber->brevoLocale !== null) {
                $this->queue->remove($subscriber->brevoEmail, $subscriber->store, $subscriber->brevoLocale);
            }
        });
    }
}
