<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One company, as staff review it (b2b.md §3.2, §4.6): its status, details, account holder and the
 * applications it sent, newest first — never a draft — and what this reader may do next.
 *
 * The screen is told what it may offer rather than working it out (StaffCompanyActionsForReader);
 * every handler behind a button asks again.
 */
#[TypeScript]
final class StaffCompanyPage extends Data
{
    /**
     * @param  list<StaffApplicationData>  $applications  newest first
     * @param  list<StaffTypeChoiceData>  $typeChoices  for Correct Company Type; empty without the job
     */
    public function __construct(
        public StaffCompanyData $company,
        public ?StaffHolderData $holder,
        public array $applications,
        public StaffCompanyActionsData $actions,
        public array $typeChoices,
    ) {}
}
