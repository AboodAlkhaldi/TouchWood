<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/**
 * The hand-picker's answer (promotions.md §1.5): the customers matching what staff typed who may be
 * picked for the segment — by name only — each marked when already a member; `more` when the text
 * found more than are shown, so the screen asks for more letters.
 */
final readonly class SegmentCandidates
{
    /**
     * @param  list<CandidateRow>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $more,
    ) {}
}
