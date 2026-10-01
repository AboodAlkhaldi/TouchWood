<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One application the company sent, in its history (b2b.md §3.1): its values, papers and dates, its
 * state, its reason or note, what its rejection marked and asked for, and the answers it gave.
 * **No staff names.**
 */
final readonly class SentApplicationView
{
    /**
     * @param  list<FileView>  $documents
     * @param  list<FlagView>  $flags
     * @param  list<RequestView>  $requests
     * @param  list<AnswerView>  $answers
     */
    public function __construct(
        public string $id,
        /** SUBMITTED, APPROVED or REJECTED. */
        public string $state,
        /** Its number, given when it was sent: TW-CO-26-0001 (amendment 14(g)). */
        public string $reference,
        public ApplicationValues $values,
        public ?string $submittedAt,
        public ?string $decidedAt,
        /** The rejection's reason, or the approval's note when staff wrote one. */
        public ?string $decisionReason,
        public array $documents,
        public array $flags,
        public array $requests,
        public array $answers,
    ) {}
}
