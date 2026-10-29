<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSubscribers;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Repository\Subscribers;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The subscribers list (ops.md §1.9, §3, §4), under `ops.newsletter.manage` in the store: guests -
 * confirmed or still waiting - and customers who agreed, with the email, the language and since when.
 * A customer's email is shown only to a reader who may view that customer (AccessApi::customerViewStores;
 * a customer's row is in their home store); a guest's is the list's own.
 */
final readonly class ListSubscribersHandler
{
    public const int PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Subscribers $subscribers,
        private AccessApi $access,
    ) {}

    /** @throws InvalidOpsInput|Unauthorized */
    public function handle(ListSubscribers $query): SubscribersPage
    {
        $store = $this->writers->store($query->storeId);
        $this->authorizer->authorize(OpsPermissions::NEWSLETTER_MANAGE, PermissionScope::store($store->storeId()));

        $rows = $this->subscribers->page($store->storeId(), InboxReaders::id($query->after, 'after'), self::PAGE + 1);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        $viewable = $this->access->customerViewStores();
        $mayView = $viewable === null || in_array($store->id, array_map(static fn (StoreId $id): string => $id->value, $viewable), true);
        $hidden = [];

        foreach ($rows as $row) {
            if (! $row->isGuest() && ! $mayView) {
                $hidden[] = $row->id;
            }
        }

        return new SubscribersPage($rows, $more && $last !== null ? $last->id : null, $hidden);
    }
}
