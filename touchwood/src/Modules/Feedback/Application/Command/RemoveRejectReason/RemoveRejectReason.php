<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RemoveRejectReason;

/** A ready reason removed (feedback.md §2.4 B4); a decision that used it keeps its words. */
final readonly class RemoveRejectReason
{
    public function __construct(
        public string $reasonId,
    ) {}
}
