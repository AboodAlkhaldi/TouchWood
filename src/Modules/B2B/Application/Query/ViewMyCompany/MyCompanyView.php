<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * Everything the company account is shown about its company **in the store it is browsing** (b2b.md
 * §3.1, amendment 19(c)). **It always answers.**
 */
final readonly class MyCompanyView
{
    /**
     * @param  list<TypeOption>  $companyTypes  what the form offers, in order
     * @param  list<TypeOption>  $documentTypes  what the form offers, in order
     * @param  list<SentApplicationView>  $history  newest first; empty before the first is sent
     * @param  list<SavedAddressView>  $savedAddresses  store by store, each store's default first
     * @param  list<ElsewhereView>  $elsewhere  the account's companies in the other stores, newest first
     */
    public function __construct(
        /** Before there is a company here, which step the account is on (§4.3); null once there is one. */
        public ?AccountStage $stage,
        public ?CompanyView $company,
        /** The open draft here, if there is one — not a sent application still waiting, which is history. */
        public ?DraftView $draft,
        public array $companyTypes,
        public array $documentTypes,
        public array $history,
        /**
         * Where to transfer a payment: only while the company is approved, and only once its store
         * has filled in all three (amendment 12(b)); null otherwise.
         */
        public ?BankAccountView $bankAccount,
        /** The store this page is about — the one being browsed: its clock is the page's (HANDOFF §4). */
        public string $homeStoreId,
        /** The account's saved addresses, which the address is picked from (amendment 16(f)). */
        public array $savedAddresses,
        public array $elsewhere = [],
        /**
         * What "Apply in this store" starts the form with (amendment 19(b)): set only while the
         * account has no company and no draft here but has a company in another store.
         */
        public ?PrefillView $prefill = null,
    ) {}
}
