<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * Everything the company account is shown about its company (b2b.md §3.1). **It always answers.**
 */
final readonly class MyCompanyView
{
    /**
     * @param  list<TypeOption>  $companyTypes  what the form offers, in order
     * @param  list<TypeOption>  $documentTypes  what the form offers, in order
     * @param  list<SentApplicationView>  $history  newest first; empty before the first is sent
     */
    public function __construct(
        /** Before there is a company, which step the account is on (§4.3); null once there is one. */
        public ?AccountStage $stage,
        public ?CompanyView $company,
        /** The open draft, if there is one — not a sent application still waiting, which is history. */
        public ?DraftView $draft,
        public array $companyTypes,
        public array $documentTypes,
        public array $history,
    ) {}
}
