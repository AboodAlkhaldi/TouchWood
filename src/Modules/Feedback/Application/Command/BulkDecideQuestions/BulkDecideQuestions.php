<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\BulkDecideQuestions;

/** A decision on many questions of a store at once (feedback.md §2.4 B2): reject, unpublish. */
final readonly class BulkDecideQuestions
{
    /**
     * @param  list<string>  $questionIds
     */
    public function __construct(
        public string $storeId,
        public string $action,
        public array $questionIds,
        /** One optional reason for all, rejecting or unpublishing; the customer sees it. */
        public ?string $reason = null,
    ) {}
}
