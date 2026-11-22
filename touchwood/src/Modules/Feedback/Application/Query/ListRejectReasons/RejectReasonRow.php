<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListRejectReasons;

/** A ready reason as the dialogs and the admins' list read it (feedback.md §2.4 B4). */
final readonly class RejectReasonRow
{
    public function __construct(
        public string $id,
        public string $textAr,
        public string $textEn,
        public int $position,
    ) {}
}
