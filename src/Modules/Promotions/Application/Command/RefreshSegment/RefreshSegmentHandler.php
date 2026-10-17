<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RefreshSegment;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Segments;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Refreshes a segment now, as the nightly run would (promotions.md §1.5): a rule's segment refilled
 * from the store's orders; a hand-picked one loses its anonymized members. Audited with how many it
 * held and holds.
 */
final readonly class RefreshSegmentHandler
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
    public function handle(RefreshSegment $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->segmentStore(self::PERMISSION, $command->segmentId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $segment = $this->segments->lock($storeId, $command->segmentId) ?? throw new SegmentNotFound;
            $now = CarbonImmutable::now();
            $before = count($this->segments->memberIds($segment->id));
            $members = $this->membership->of($segment, $now);
            $this->segments->replaceMembers($segment, $members, $now);
            $this->segments->markRefreshed($segment, $now);

            return [null, array_filter([CouponAudit::entry('refreshed', $segment->id, $storeId->value, ['members' => $before], ['members' => count($members)], 'segment')])];
        });
    }
}
