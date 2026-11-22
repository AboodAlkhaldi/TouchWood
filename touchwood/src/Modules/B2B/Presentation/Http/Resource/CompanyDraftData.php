<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The account's open draft, as the form shows it (b2b.md §3.1, §4.5): its values and papers, what
 * the last rejection marked and asked for, and the answers given so far.
 */
#[TypeScript]
final class CompanyDraftData extends Data
{
    /**
     * @param  list<CompanyFileData>  $documents
     * @param  list<CompanyFlagData>  $flags
     * @param  list<CompanyRequestData>  $requests
     * @param  list<CompanyAnswerData>  $answers
     */
    public function __construct(
        public string $id,
        public CompanyValuesData $values,
        /** A listed type staff deactivated since it was chosen: choose again (§1.3). */
        public bool $typeNoLongerAccepted,
        public array $documents,
        public array $flags,
        public array $requests,
        public array $answers,
    ) {}
}
