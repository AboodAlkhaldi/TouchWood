<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\DeleteSegment;

use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Segments;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Deletes a segment and its members (promotions.md §1.5, §3). Coupons already assigned from it keep
 * their customers (§1.4) — the assignment only forgets which segment it came from. Audited.
 */
final readonly class DeleteSegmentHandler
{
    public const string PERMISSION = PromotionsPermissions::SEGMENT_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Segments $segments,
    ) {}

    /**
     * @throws Unauthorized|SegmentNotFound|InvalidPromotionAttribute
     */
    public function handle(DeleteSegment $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->segmentStore(self::PERMISSION, $command->segmentId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $segment = $this->segments->lock($storeId, $command->segmentId) ?? throw new SegmentNotFound;
            $members = count($this->segments->memberIds($segment->id));
            $this->segments->delete($segment);

            return [null, array_filter([CouponAudit::entry('deleted', $segment->id, $storeId->value, [...$segment->snapshot(), 'members' => $members], [], 'segment')])];
        });
    }
}
