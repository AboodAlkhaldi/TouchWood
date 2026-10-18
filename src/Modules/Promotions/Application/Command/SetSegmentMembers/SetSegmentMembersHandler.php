<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\SetSegmentMembers;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Public\Enums\SegmentType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sets a hand-picked segment's members, all of them (promotions.md §1.5): each a customer of its
 * audience, not anonymized, registered in its store or who ordered there — whose name its staff see,
 * as on that store's orders (owner, 2026-10-09). A segment filled by a rule is not picked by hand.
 * Audited with how many it holds.
 */
final readonly class SetSegmentMembersHandler
{
    public const string PERMISSION = PromotionsPermissions::SEGMENT_MANAGE;

    /** The most a hand-picked segment holds in one save: a list a screen can send. */
    public const int MOST = 10_000;

    public function __construct(
        private MarketingChange $change,
        private Segments $segments,
        private SegmentMembership $membership,
    ) {}

    /**
     * @throws Unauthorized|SegmentNotFound|InvalidPromotionAttribute
     */
    public function handle(SetSegmentMembers $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->segmentStore(self::PERMISSION, $command->segmentId));
        $storeId = StoreId::fromString($store->id);

        if (count($command->customerIds) > self::MOST) {
            throw new InvalidPromotionAttribute('members', 'at most '.self::MOST);
        }

        $customerIds = array_map(static fn (string $id): string => Inputs::id($id, 'members'), $command->customerIds);

        $this->change->run($store, function () use ($storeId, $command, $customerIds): array {
            $segment = $this->segments->lock($storeId, $command->segmentId) ?? throw new SegmentNotFound;

            if ($segment->type !== SegmentType::Static) {
                throw new InvalidPromotionAttribute('segment_type', 'a rule fills this segment');
            }

            $before = count($this->segments->memberIds($segment->id));
            $members = $this->membership->picked($segment, $customerIds);
            $changed = $this->segments->replaceMembers($segment, $members, CarbonImmutable::now());

            // Who came and went, not only how many: one member swapped for another is still a change.
            return [null, $changed['added'] + $changed['removed'] === 0 ? [] : array_filter([CouponAudit::entry(
                'members_set', $segment->id, $storeId->value,
                ['members' => $before],
                ['members' => count($members), 'added' => $changed['added'], 'removed' => $changed['removed']],
                'segment',
            )])];
        });
    }
}
