<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\CandidateRow;
use Modules\Promotions\Application\Query\ListSegmentMembers\ListSegmentMembers;
use Modules\Promotions\Application\Query\ListSegmentMembers\ListSegmentMembersHandler;
use Modules\Promotions\Application\Query\ListSegments\ListSegments;
use Modules\Promotions\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Promotions\Application\Query\MemberRow;
use Modules\Promotions\Application\Query\PromotionsActions\PromotionsActionsForReader;
use Modules\Promotions\Application\Query\SearchSegmentCandidates\SearchSegmentCandidates;
use Modules\Promotions\Application\Query\SearchSegmentCandidates\SearchSegmentCandidatesHandler;
use Modules\Promotions\Application\Query\SegmentRow;
use Modules\Promotions\Application\Query\ViewSegment\ViewSegment;
use Modules\Promotions\Application\Query\ViewSegment\ViewSegmentHandler;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Public\Enums\SegmentType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The segment screens' data (promotions.md §4.1 M3), built from Promotions' Application queries - which
 * authorize - and nothing of its own deciding: what a reader may do comes from
 * PromotionsActionsForReader.
 */
final readonly class SegmentPages
{
    public function __construct(
        private MarketingScreen $screen,
        private PlatformApi $platform,
        private PromotionsActionsForReader $actions,
    ) {}

    /**
     * M3's list.
     *
     * @throws Unauthorized
     */
    public function list(?string $storeCode, ?string $beforeAt, ?string $beforeId, ListSegmentsHandler $segments): SegmentsListPage
    {
        [$store, $offered] = $this->screen->store($storeCode);
        $page = $segments->handle(new ListSegments($store->id, MarketingScreen::before($beforeAt), $beforeId));

        return new SegmentsListPage(
            array_map($this->row(...), $page->rows),
            $page->nextAt === null ? null : MarketingScreen::cursor($page->nextAt),
            $page->nextId,
            $this->actions->forStore($store->storeId())->mayManageSegments,
            ...$this->screen->shown($store),
            stores: $this->screen->options($offered),
        );
    }

    /**
     * M3's page of one segment, its members from `afterMember` on, and what a manager's search found.
     *
     * @throws Unauthorized|SegmentNotFound
     */
    public function segment(
        string $segmentId,
        ?string $afterMember,
        ?string $search,
        ViewSegmentHandler $view,
        ListSegmentMembersHandler $members,
        SearchSegmentCandidatesHandler $candidates,
    ): SegmentPage {
        $segment = $view->handle(new ViewSegment($segmentId));
        $store = $this->platform->store(StoreId::fromString($segment->storeId)) ?? throw new Unauthorized(PromotionsPermissions::VIEW);
        $mayManage = $this->actions->forStore($store->storeId())->mayManageSegments;
        $page = $members->handle(new ListSegmentMembers($segment->id, $afterMember));
        // The hand-picker searches only for a manager, only a segment picked by hand (§1.5).
        $found = $search !== null && $mayManage && $segment->type === SegmentType::Static
            ? $candidates->handle(new SearchSegmentCandidates($segment->id, $search))
            : null;

        return new SegmentPage(
            $this->row($segment),
            array_map(static fn (MemberRow $member): MemberData => new MemberData(CustomerNameData::of($member->customer), MarketingScreen::moment($member->addedAt), true), $page->rows),
            $page->nextCustomerId,
            $found === null ? [] : array_map(static fn (CandidateRow $row): MemberData => new MemberData(CustomerNameData::of($row->customer), null, $row->member), $found->rows),
            $found === null ? null : $search,
            $found->more ?? false,
            $mayManage,
            ...$this->screen->shown($store),
        );
    }

    private function row(SegmentRow $row): SegmentRowData
    {
        return new SegmentRowData(
            $row->id,
            $this->screen->name($row->nameAr, $row->nameEn),
            $row->nameAr,
            $row->nameEn,
            $row->type->value,
            $row->audience->value,
            $row->rule?->value,
            $row->ruleMonths,
            $row->ruleOrders,
            $row->memberCount,
            $row->refreshedAt === null ? null : MarketingScreen::moment($row->refreshedAt),
        );
    }
}
