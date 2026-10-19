<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\SearchSegmentCandidates;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\CandidateRow;
use Modules\Promotions\Application\Query\CustomerNames;
use Modules\Promotions\Application\Query\SegmentCandidates;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Segments;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * The segment hand-picker's search (promotions.md §1.5, §9.2 #6; access.md amendments 70, 72(b)):
 * under `promotions.segment.manage` in the segment's store, the text sent to Access's search —
 * names in any store, email and phone among the customers the staff member may view — then only the
 * customers the segment may hold kept (`SegmentMembership::eligible`, the rule a save enforces), each
 * **by name only**, at most 50, in id order, marked when already a member.
 */
final readonly class SearchSegmentCandidatesHandler
{
    public const string PERMISSION = PromotionsPermissions::SEGMENT_MANAGE;

    /** Access's matches read at once: more than this asks for more letters. */
    private const int MATCHES = 1000;

    public const int SHOWN = 50;

    public function __construct(
        private MarketingChange $change,
        private Segments $segments,
        private AccessApi $access,
        private SegmentMembership $membership,
        private CustomerNames $names,
    ) {}

    /**
     * @throws Unauthorized|SegmentNotFound|DomainError
     */
    public function handle(SearchSegmentCandidates $query): SegmentCandidates
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->segmentStore(self::PERMISSION, $query->segmentId));
        $segment = $this->segments->byId(StoreId::fromString($store->id), strtolower($query->segmentId)) ?? throw new SegmentNotFound;

        $matches = $this->access->customerIdsMatching($query->text, self::MATCHES);
        $eligible = $this->membership->eligible($segment, $matches);
        $shown = array_slice($eligible, 0, self::SHOWN);
        $names = $shown === [] ? [] : $this->names->of($shown);
        $members = array_flip($this->segments->membersAmong($segment->id, $shown));

        return new SegmentCandidates(
            array_map(static fn (string $id): CandidateRow => new CandidateRow(CustomerNames::pick($names, $id), isset($members[$id])), $shown),
            count($eligible) > self::SHOWN || count($matches) >= self::MATCHES,
        );
    }
}
