<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListSegmentMembers;

use Modules\Promotions\Application\Query\CustomerNames;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Application\Query\MemberRow;
use Modules\Promotions\Application\Query\MembersPage;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Segments;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A segment's members (promotions.md §1.5, §3): **the name only** — a member may be registered in
 * another store and have ordered here (access.md amendment 70) — a page at a time by customer id; the
 * names in one Access call. A segment of a store not covered is not found, as an unknown one.
 */
final readonly class ListSegmentMembersHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private Segments $segments,
        private MarketingQueries $queries,
        private CustomerNames $names,
    ) {}

    /**
     * @throws Unauthorized|SegmentNotFound
     */
    public function handle(ListSegmentMembers $query): MembersPage
    {
        [$store, $permission] = $this->reader->covered(fn () => $this->segments->storeOf($query->segmentId), static fn (): SegmentNotFound => new SegmentNotFound);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        $members = $this->queries->members(strtolower($query->segmentId), $query->afterCustomerId, $perPage + 1);
        $more = count($members) > $perPage;
        $members = array_slice($members, 0, $perPage);
        $names = $members === [] ? [] : $this->names->of(array_column($members, 'customerId'));

        $rows = array_map(static fn (array $member): MemberRow => new MemberRow(CustomerNames::pick($names, $member['customerId']), $member['addedAt']), $members);
        $last = $more ? end($rows) : false;

        return new MembersPage($rows, $last === false ? null : $last->customer->id);
    }
}
