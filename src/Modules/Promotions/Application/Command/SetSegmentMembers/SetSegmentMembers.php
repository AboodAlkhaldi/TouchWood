<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\SetSegmentMembers;

final readonly class SetSegmentMembers
{
    /**
     * @param  list<string>  $customerIds  the hand-picked segment's members, all of them
     */
    public function __construct(
        public string $segmentId,
        public array $customerIds,
    ) {}
}
