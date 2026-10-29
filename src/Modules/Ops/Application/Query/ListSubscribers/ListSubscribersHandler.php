<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSubscribers;

use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Repository\Subscribers;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The subscribers list (ops.md §1.9, §3, §4), under `ops.newsletter.manage` in the store: guests -
 * confirmed or still waiting - and customers who agreed, with the email, the language and since when.
 */
final readonly class ListSubscribersHandler
{
    public const int PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Subscribers $subscribers,
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

        return new SubscribersPage($rows, $more && $last !== null ? $last->id : null);
    }
}
