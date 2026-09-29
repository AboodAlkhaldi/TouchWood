<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RejectCompany;

/**
 * Staff rejecting the application a company sent (b2b.md §3.2): a reason is required; staff may
 * flag the items sent wrong — any of the five fields, or a document the application sent — and ask
 * this one company for extra items, each a text answer or a file with a label (amendment 4).
 */
final readonly class RejectCompany
{
    /**
     * @param  list<string>  $flaggedFields  name, company_type, cr_number, tax_number, address
     * @param  list<string>  $flaggedDocumentTypeIds  types the application sent a file under
     * @param  list<array{kind: string, label: string}>  $requests  kind TEXT or FILE, in their order
     */
    public function __construct(
        public string $companyId,
        public string $reason,
        public array $flaggedFields = [],
        public array $flaggedDocumentTypeIds = [],
        public array $requests = [],
    ) {}
}
