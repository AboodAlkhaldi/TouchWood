<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Model;

/** A ready reason (feedback.md §2.4 B4): its words in both languages, and its place in the list from 1. */
final readonly class RejectReason
{
    public function __construct(
        public string $id,
        public string $textAr,
        public string $textEn,
        public int $position,
    ) {}
}
