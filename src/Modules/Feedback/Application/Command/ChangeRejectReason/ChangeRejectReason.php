<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\ChangeRejectReason;

/** A ready reason's words changed (feedback.md §2.4 B4). */
final readonly class ChangeRejectReason
{
    public function __construct(
        public string $reasonId,
        public string $textAr,
        public string $textEn,
    ) {}
}
