<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeSegment;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Model\SegmentTerms;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Public\Enums\SegmentType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Renames a segment, or changes its rule or the rule's numbers — "no order in 6 months" to 12
 * (promotions.md §1.5). Its type and audience stay as they were made (owner, 2026-10-18, answer 23):
 * to change them, staff make a new segment. A changed rule refills the segment at once. Audited — only
 * what changed.
 */
final readonly class ChangeSegmentHandler
{
    public const string PERMISSION = PromotionsPermissions::SEGMENT_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Segments $segments,
        private SegmentMembership $membership,
    ) {}

    /**
     * @throws Unauthorized|SegmentNotFound|InvalidPromotionAttribute
     */
    public function handle(ChangeSegment $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->segmentStore(self::PERMISSION, $command->segmentId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $segment = $this->segments->lock($storeId, $command->segmentId) ?? throw new SegmentNotFound;
            $terms = SegmentTerms::of($command->nameAr, $command->nameEn, $segment->type->value, $segment->audience->value, $command->rule, $command->ruleMonths, $command->ruleOrders);
            $entry = CouponAudit::entry('updated', $segment->id, $storeId->value, $segment->snapshot(), $terms->snapshot(), 'segment');

            if ($entry === null) {
                return [null, []];
            }

            $now = CarbonImmutable::now();
            $this->segments->change($segment, $terms, $now);

            if ($segment->type === SegmentType::Dynamic && [$segment->rule, $segment->ruleMonths, $segment->ruleOrders] !== [$terms->rule, $terms->ruleMonths, $terms->ruleOrders]) {
                $changed = $this->segments->lock($storeId, $segment->id) ?? throw new SegmentNotFound;
                $this->segments->replaceMembers($changed, $this->membership->of($changed, $now), $now);
                $this->segments->markRefreshed($changed, $now);
            }

            return [null, [$entry]];
        });
    }
}
