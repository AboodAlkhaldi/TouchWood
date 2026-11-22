<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSegments;

use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The segments a form may pick from (ops.md §4.3, §4.4), under the form's own permission in the store:
 * `PromotionsApi::storeSegments` asks for none, so this is the only check.
 */
final readonly class ListSegmentsHandler
{
    public const int PAGE = 20;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private PromotionsApi $promotions,
    ) {}

    /** @throws InvalidOpsInput|Unauthorized */
    public function handle(ListSegments $query): SegmentsPage
    {
        $store = $this->writers->store($query->storeId);
        $this->authorizer->authorize($query->permission, PermissionScope::store($store->storeId()));

        // One more than a page: whether a next page exists, without a count.
        $segments = $this->promotions->storeSegments($store->storeId(), InboxReaders::id($query->after, 'after'), self::PAGE + 1);
        $more = count($segments) > self::PAGE;
        $segments = array_slice($segments, 0, self::PAGE);
        $last = $segments === [] ? null : $segments[count($segments) - 1];

        return new SegmentsPage($segments, $more && $last !== null ? $last->id : null);
    }
}
