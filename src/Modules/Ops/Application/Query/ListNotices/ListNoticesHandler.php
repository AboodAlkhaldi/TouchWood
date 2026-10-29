<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListNotices;

use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Repository\Notices;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The notices list (ops.md §1.7, §4), under `ops.notice.manage` in the store: each notice with its
 * status and how many it reached - by the bell, by email, and the emails that failed.
 */
final readonly class ListNoticesHandler
{
    public const int PAGE = 20;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Notices $notices,
    ) {}

    /** @throws InvalidOpsInput|Unauthorized */
    public function handle(ListNotices $query): NoticesPage
    {
        $store = $this->writers->store($query->storeId);
        $this->authorizer->authorize(OpsPermissions::NOTICE_MANAGE, PermissionScope::store($store->storeId()));

        // One more than a page: whether a next page exists, without a count.
        $notices = $this->notices->page($store->storeId(), InboxReaders::id($query->after, 'after'), self::PAGE + 1);
        $more = count($notices) > self::PAGE;
        $notices = array_slice($notices, 0, self::PAGE);
        $last = $notices === [] ? null : $notices[count($notices) - 1];

        return new NoticesPage($notices, $more && $last !== null ? $last->id : null);
    }
}
