<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RefreshSegments;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Repository\Segments;
use Psr\Log\LoggerInterface;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * Refreshes every store's segments, nightly (promotions.md §1.5) — the one pass across stores (§1.8).
 * Each segment in its own transaction under its own lock; one that fails is logged and the rest go on,
 * as Loyalty's expiry does — tomorrow's run takes it again. Not audited: the system, not a person.
 *
 * @return int how many segments were refreshed
 */
final readonly class RefreshSegmentsHandler
{
    public const string PERMISSION = PromotionsPermissions::SEGMENT_REFRESH;

    public function __construct(
        private Authorizer $authorizer,
        private ConnectionInterface $db,
        private Segments $segments,
        private SegmentMembership $membership,
        private LoggerInterface $log,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(RefreshSegments $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $refreshed = 0;
        $after = null;

        do {
            $page = $this->segments->pageAcrossStores($after, $command->page);

            foreach ($page as $segment) {
                try {
                    // Deleted since the page was read: nothing to refresh, and not counted.
                    $refreshed += $this->db->transaction(function () use ($segment): int {
                        $locked = $this->segments->lock(StoreId::fromString($segment->storeId), $segment->id);

                        if ($locked === null) {
                            return 0;
                        }

                        $now = CarbonImmutable::now();
                        $this->segments->replaceMembers($locked, $this->membership->of($locked, $now), $now);
                        $this->segments->markRefreshed($locked, $now);

                        return 1;
                    });
                } catch (Throwable $error) {
                    $this->log->warning('promotions: a segment could not be refreshed', ['segment' => $segment->id, 'store' => $segment->storeId, 'error' => $error->getMessage()]);
                }
            }

            $after = $page === [] ? null : $page[array_key_last($page)]->id;
        } while (count($page) === $command->page);

        return $refreshed;
    }
}
