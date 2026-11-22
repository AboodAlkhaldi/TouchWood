<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSubscribers;

use Modules\Ops\Domain\Model\Subscriber;

/**
 * A page of a store's newsletter list (ops.md §1.9): `next` is what to ask for the following page, null
 * on the last; `hiddenEmails` the ids of customers' rows whose email the reader may not see.
 */
final readonly class SubscribersPage
{
    /**
     * @param  list<Subscriber>  $subscribers
     * @param  list<string>  $hiddenEmails
     */
    public function __construct(
        public array $subscribers,
        public ?string $next,
        public array $hiddenEmails = [],
    ) {}
}
