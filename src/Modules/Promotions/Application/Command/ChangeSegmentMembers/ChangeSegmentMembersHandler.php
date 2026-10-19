<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeSegmentMembers;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
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
 * A manager's adds and removals to a hand-picked segment (promotions.md §1.5), **merged under the
 * segment's lock**: two managers saving at once each keep what they did, never one list over the other
 * (the screens' review, 2026-10-20). **Only the customers added are checked** — of the audience, not
 * anonymized, of the store — so a member who is no longer one of them (an account anonymized since)
 * never blocks a save; members already in stay as they are (my assumption). An id both added and taken
 * off is taken off. At most `SetSegmentMembersHandler::MOST` members after the change; audited as a
 * whole-list save is, who came and went.
 */
final readonly class ChangeSegmentMembersHandler
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
    public function handle(ChangeSegmentMembers $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->segmentStore(self::PERMISSION, $command->segmentId));
        $storeId = StoreId::fromString($store->id);

        if (count($command->add) > SetSegmentMembersHandler::MOST || count($command->remove) > SetSegmentMembersHandler::MOST) {
            throw new InvalidPromotionAttribute('members', 'at most '.SetSegmentMembersHandler::MOST);
        }

        $remove = array_values(array_unique(array_map(static fn (string $id): string => Inputs::id($id, 'members'), $command->remove)));
        $add = array_values(array_diff(array_unique(array_map(static fn (string $id): string => Inputs::id($id, 'members'), $command->add)), $remove));

        $this->change->run($store, function () use ($storeId, $command, $add, $remove): array {
            $segment = $this->segments->lock($storeId, $command->segmentId) ?? throw new SegmentNotFound;

            if ($segment->type !== SegmentType::Static) {
                throw new InvalidPromotionAttribute('segment_type', 'a rule fills this segment');
            }

            $current = $this->segments->memberIds($segment->id);
            $added = $this->membership->picked($segment, array_values(array_diff($add, $current)));
            $members = array_values(array_unique([...array_diff($current, $remove), ...$added]));
            sort($members);

            if (count($members) > SetSegmentMembersHandler::MOST) {
                throw new InvalidPromotionAttribute('members', 'at most '.SetSegmentMembersHandler::MOST);
            }

            $changed = $this->segments->replaceMembers($segment, $members, CarbonImmutable::now());

            return [null, $changed['added'] + $changed['removed'] === 0 ? [] : array_filter([CouponAudit::entry(
                'members_set', $segment->id, $storeId->value,
                ['members' => count($current)],
                ['members' => count($members), 'added' => $changed['added'], 'removed' => $changed['removed']],
                'segment',
            )])];
        });
    }
}
