<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * The account's open draft, as the wizard shows it (b2b.md §3.1): its values and papers, what the
 * last rejection marked and asked for, the answers given so far, and what is no longer accepted.
 */
final readonly class DraftView
{
    /**
     * @param  list<FileView>  $documents
     * @param  list<FlagView>  $flags  what the last rejection marked, to be replaced before sending
     * @param  list<RequestView>  $requests  what the last rejection asked for, to be answered
     * @param  list<AnswerView>  $answers  this draft's answers so far
     */
    public function __construct(
        public string $id,
        public ApplicationValues $values,
        /** A listed type staff deactivated since it was chosen: choose again (§1.3). */
        public bool $typeNoLongerAccepted,
        public array $documents,
        public array $flags,
        public array $requests,
        public array $answers,
    ) {}
}
