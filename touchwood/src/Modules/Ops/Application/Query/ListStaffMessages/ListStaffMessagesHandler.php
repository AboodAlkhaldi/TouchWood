<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListStaffMessages;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The staff messages list (ops.md §1.8, §4; owner, 2026-11-01), by who may send each: a message to one
 * customer under `ops.message.send_one` in the store **and** viewing its customers - it names them; a
 * message to many under `ops.message.send_many`. Someone holding neither there is refused.
 */
final readonly class ListStaffMessagesHandler
{
    public const int PAGE = 20;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private AccessApi $access,
        private StaffMessageQueries $messages,
    ) {}

    /** @throws InvalidOpsInput|Unauthorized */
    public function handle(ListStaffMessages $query): StaffMessagesPage
    {
        $store = $this->writers->store($query->storeId);
        $scope = PermissionScope::store($store->storeId());
        $many = Writers::among($store->storeId(), $this->authorizer->storesWith(OpsPermissions::MESSAGE_SEND_MANY));
        $one = Writers::among($store->storeId(), $this->authorizer->storesWith(OpsPermissions::MESSAGE_SEND_ONE));
        $this->authorizer->authorize($many ? OpsPermissions::MESSAGE_SEND_MANY : OpsPermissions::MESSAGE_SEND_ONE, $scope);

        $audiences = [
            ...($many ? ['STORE', 'SEGMENT'] : []),
            ...($one && Writers::among($store->storeId(), $this->access->customerViewStores()) ? ['ONE'] : []),
        ];
        $after = InboxReaders::id($query->after, 'after');

        if ($audiences === []) {
            return new StaffMessagesPage([], null);
        }

        // One more than a page: whether a next page exists, without a count.
        $rows = $this->messages->page($store->storeId(), $audiences, $after, self::PAGE + 1);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new StaffMessagesPage($rows, $more && $last !== null ? $last->id : null);
    }
}
