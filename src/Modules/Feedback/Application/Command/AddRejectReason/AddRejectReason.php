<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\AddRejectReason;

/** A ready reason added last (feedback.md §2.4 B4). */
final readonly class AddRejectReason
{
    public function __construct(
        public string $textAr,
        public string $textEn,
    ) {}
}
