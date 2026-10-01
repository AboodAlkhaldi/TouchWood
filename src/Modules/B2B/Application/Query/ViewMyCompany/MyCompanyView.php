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
     * @param  list<SavedAddressView>  $savedAddresses  store by store, each store's default first
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
        /**
         * Where to transfer a payment: only while the company is approved, and only once its home
         * store has filled in all three (amendment 12(b)); null otherwise.
         */
        public ?BankAccountView $bankAccount,
        /** The company's store, or the account's before there is one: its clock is the page's (HANDOFF §4). */
        public string $homeStoreId,
        /** The account's saved addresses, which the address is picked from (amendment 16(f)). */
        public array $savedAddresses,
    ) {}
}
