<?php

declare(strict_types=1);

// What each audited B2B action is called, for the audit log (frontend.md 3.5, E6). An action with
// no line here is shown exactly as it is recorded.
return [
    'application.submitted' => 'Company application sent',
    'application.discarded' => 'Draft company application discarded',
    'company.address_changed' => 'Company address changed',
    // The system, when the account is anonymized (step 5, amendment 12(a)).
    'company.anonymized' => 'Company emptied with its anonymized account',
    // Staff (step 4, amendment 10).
    'application.approved' => 'Company application approved',
    'application.rejected' => 'Company application rejected',
    'company.suspended' => 'Company suspended',
    'company.reinstated' => 'Company reinstated',
    'company.type_corrected' => 'Company type corrected',
    'company.type_replaced' => 'Company type replaced',
    'company.type_transferred' => 'Company moved to another type',
    'company.document_opened' => 'Company paper opened',
    'company_type.added' => 'Company type added',
    'company_type.renamed' => 'Company type renamed',
    'company_type.moved' => 'Company type moved',
    'company_type.deactivated' => 'Company type deactivated',
    'company_type.activated' => 'Company type reactivated',
    'company_type.transferred' => 'Companies moved from one type to another',
    'document_type.added' => 'Document type added',
    'document_type.renamed' => 'Document type renamed',
    'document_type.moved' => 'Document type moved',
    'document_type.requirement_changed' => 'Document type made required or optional',
    'document_type.deactivated' => 'Document type deactivated',
    'document_type.activated' => 'Document type reactivated',
    'type_lists.reviewed' => 'Company and document types marked reviewed',
];
