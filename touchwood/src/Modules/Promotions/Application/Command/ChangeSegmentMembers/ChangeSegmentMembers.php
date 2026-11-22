<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeSegmentMembers;

/** Customers added to and taken off a hand-picked segment, by a manager's one save (promotions.md §1.5). */
final readonly class ChangeSegmentMembers
{
    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function __construct(
        public string $segmentId,
        public array $add,
        public array $remove,
    ) {}
}
