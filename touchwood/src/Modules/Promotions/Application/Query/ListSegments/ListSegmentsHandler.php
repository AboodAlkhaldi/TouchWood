<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListSegments;

use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Application\Query\SegmentsPage;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A store's segments (promotions.md §3): the view permission or a manage one in the store; newest
 * first, each with how many members it holds, a page at a time by keyset.
 */
final readonly class ListSegmentsHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private MarketingQueries $queries,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListSegments $query): SegmentsPage
    {
        [$store, $permission] = $this->reader->store($query->storeId);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        $rows = $this->queries->segments($store, $query->beforeAt, $query->beforeId, $perPage + 1);
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $last = $more ? end($rows) : false;

        return new SegmentsPage($rows, $last === false ? null : $last->createdAt, $last === false ? null : $last->id);
    }
}
