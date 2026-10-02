<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One application the company sent, as staff read it (b2b.md §3.2, §4.6): what it sent, its papers,
 * the decision and who made it, what a rejection marked and asked for, and the answers it gave to
 * the requests of the application before it. Times are the home store's (HANDOFF §4).
 */
#[TypeScript]
final class StaffApplicationData extends Data
{
    /**
     * @param  list<CompanyFileData>  $documents
     * @param  list<CompanyFlagData>  $flags  what this application's rejection marked
     * @param  list<CompanyRequestData>  $requests  what this application's rejection asked for
     * @param  list<StaffAnswerData>  $answers  this application's answers to the previous one's requests
     */
    public function __construct(
        public string $id,
        /** TW-CO-26-0001 (amendment 14(g)). */
        public string $reference,
        /** SUBMITTED, APPROVED or REJECTED — never a draft. */
        public string $state,
        public CompanyValuesData $values,
        public ?string $submittedAt,
        public ?string $decidedAt,
        /** The staff member who decided, by name; null while it waits. */
        public ?string $decidedBy,
        /** A rejection's reason, or an approval's note. */
        public ?string $decisionReason,
        public array $documents,
        public array $flags,
        public array $requests,
        public array $answers,
        /** Waiting, and its company type was deactivated since it was sent — information only (amendment 11(a)). */
        public bool $typeDeactivatedSinceSent,
    ) {}
}
