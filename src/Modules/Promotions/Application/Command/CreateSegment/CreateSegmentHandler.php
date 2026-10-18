<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\CreateSegment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\SegmentTerms;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Public\Enums\SegmentType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Creates a store's customer segment (promotions.md §1.5, §3): picked by hand — empty until staff pick
 * — or filled by one rule at once, then nightly and on demand. Its type and audience are set here and
 * never change (owner, 2026-10-18, answer 23). Audited.
 */
final readonly class CreateSegmentHandler
{
    public const string PERMISSION = PromotionsPermissions::SEGMENT_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Segments $segments,
        private SegmentMembership $membership,
    ) {}

    /**
     * @throws Unauthorized|InvalidPromotionAttribute
     */
    public function handle(CreateSegment $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $terms = SegmentTerms::of($command->nameAr, $command->nameEn, $command->type, $command->audience, $command->rule, $command->ruleMonths, $command->ruleOrders);
        $storeId = StoreId::fromString($store->id);

        return $this->change->run($store, function () use ($storeId, $terms): array {
            $segmentId = strtolower((string) Str::ulid());
            $now = CarbonImmutable::now();
            $this->segments->create($segmentId, $storeId, $terms, $now);
            $members = 0;

            if ($terms->type === SegmentType::Dynamic) {
                $segment = $this->segments->lock($storeId, $segmentId) ?? throw new \LogicException('A segment just made cannot be read.');
                $members = $this->segments->replaceMembers($segment, $this->membership->of($segment, $now), $now)['added'];
                $this->segments->markRefreshed($segment, $now);
            }

            return [$segmentId, array_filter([CouponAudit::entry('created', $segmentId, $storeId->value, [], [...$terms->snapshot(), 'members' => $members], 'segment')])];
        });
    }
}
