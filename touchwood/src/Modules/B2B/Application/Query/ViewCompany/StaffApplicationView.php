<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewCompany;

use Modules\B2B\Application\Query\ViewMyCompany\AnswerView;
use Modules\B2B\Application\Query\ViewMyCompany\ApplicationValues;
use Modules\B2B\Application\Query\ViewMyCompany\FileView;
use Modules\B2B\Application\Query\ViewMyCompany\FlagView;
use Modules\B2B\Application\Query\ViewMyCompany\RequestView;

/**
 * One application the company sent, as staff see it: what the company sees of it, and who decided.
 */
final readonly class StaffApplicationView
{
    /**
     * @param  list<FileView>  $documents
     * @param  list<FlagView>  $flags
     * @param  list<RequestView>  $requests
     * @param  list<AnswerView>  $answers
     */
    public function __construct(
        public string $id,
        public string $state,
        /** Its number, given when it was sent: TW-CO-26-0001 (amendment 14(g)). */
        public string $reference,
        public ApplicationValues $values,
        public ?string $submittedAt,
        public ?string $decidedAt,
        /** The staff member who decided, by name; null while it waits. */
        public ?string $decidedBy,
        public ?string $decisionReason,
        public array $documents,
        public array $flags,
        public array $requests,
        public array $answers,
        /** Waiting, and its company type was deactivated since it was sent — for the reviewer's information (§1.3, amendment 11(a)). */
        public bool $typeDeactivatedSinceSent,
    ) {}
}
