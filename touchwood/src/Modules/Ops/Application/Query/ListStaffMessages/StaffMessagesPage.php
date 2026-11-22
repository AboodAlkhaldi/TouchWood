<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListStaffMessages;

/** A page of a store's staff messages (ops.md §1.8): `next` is what to ask for the following page, null on the last. */
final readonly class StaffMessagesPage
{
    /**
     * @param  list<StaffMessageRow>  $messages
     */
    public function __construct(
        public array $messages,
        public ?string $next,
    ) {}
}
