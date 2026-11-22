<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ViewSegment;

use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Application\Query\SegmentRow;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Repository\Segments;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * One segment for its page (promotions.md §3): the view permission or a manage one in the segment's
 * store — its store, names, type, audience, rule, how many it holds and when last refreshed. A segment
 * of a store the reader does not cover is not found, the same answer as no such segment.
 */
final readonly class ViewSegmentHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private Segments $segments,
        private MarketingQueries $queries,
    ) {}

    /**
     * @throws Unauthorized|SegmentNotFound
     */
    public function handle(ViewSegment $query): SegmentRow
    {
        [$store, $permission] = $this->reader->covered(fn () => $this->segments->storeOf($query->segmentId), static fn (): SegmentNotFound => new SegmentNotFound);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);

        return $this->queries->segmentRow($store, $query->segmentId) ?? throw new SegmentNotFound;
    }
}
