<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Moderation;

/**
 * What a decision on many reviews or questions did (feedback.md §2.4 B2): those decided now, those another
 * staff member decided first (a state the move does not start from), and those not found in the store.
 */
final readonly class BulkDecision
{
    /**
     * @param  list<string>  $done
     * @param  list<string>  $alreadyDecided
     * @param  list<string>  $notFound
     */
    public function __construct(
        public array $done,
        public array $alreadyDecided,
        public array $notFound,
    ) {}
}
