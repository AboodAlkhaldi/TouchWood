<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One application the company sent (b2b.md §4.5, amendment 14(f)): its number, what was sent, its
 * papers, what it was told, and what it flagged or asked for. No staff names (§3.1).
 */
#[TypeScript]
final class CompanyApplicationData extends Data
{
    /**
     * @param  list<CompanyFileData>  $documents
     * @param  list<CompanyFlagData>  $flags
     * @param  list<CompanyRequestData>  $requests
     * @param  list<CompanyAnswerData>  $answers
     */
    public function __construct(
        public string $id,
        /** TW-CO-26-0001 (amendment 14(g)). */
        public string $reference,
        /** SUBMITTED, APPROVED or REJECTED. */
        public string $state,
        public CompanyValuesData $values,
        /** In the home store's time (HANDOFF §4). */
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
