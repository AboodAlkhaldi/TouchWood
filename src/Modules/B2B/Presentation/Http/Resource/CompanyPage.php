<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F11 — the company page (b2b.md §4.5, amendment 14): before the first send the draft alone;
 * afterwards the company, its open draft if any, and every application it sent.
 */
#[TypeScript]
final class CompanyPage extends Data
{
    /**
     * @param  list<CompanyTypeOptionData>  $companyTypes  what the form offers, in order
     * @param  list<CompanyTypeOptionData>  $documentTypes  what the form offers, in order
     * @param  list<CompanyApplicationData>  $history  newest first
     * @param  array<string, CompanyFieldRuleData>  $formRules  each field's rules, by the field's key
     * @param  list<CompanySavedAddressData>  $savedAddresses  store by store, each store's default first
     */
    public function __construct(
        /** Before there is a company: EMAIL_NOT_CONFIRMED, NO_APPLICATION or DRAFT_OPEN (§4.3). */
        public ?string $stage,
        public ?CompanyStatusData $company,
        public ?CompanyDraftData $draft,
        public array $companyTypes,
        public array $documentTypes,
        public array $history,
        /** Only while approved and bank transfer is on (§2.3, amendment 13(c)). */
        public ?CompanyBankAccountData $bankAccount,
        /** The largest paper the form may send: Platform's limit for a private file (platform.md §1.4). */
        public int $maxFileBytes,
        /** What each field accepts, so the page checks before sending (amendment 16(a)). */
        public array $formRules,
        /** The account's saved addresses, which the address is picked from (amendment 16(f)). */
        public array $savedAddresses,
    ) {}
}
