<?php

declare(strict_types=1);

// What each audited B2B action is called, for the audit log (frontend.md 3.5, E6). An action with
// no line here is shown exactly as it is recorded.
return [
    'application.submitted' => 'Company application sent',
    'application.discarded' => 'Draft company application discarded',
    'company.address_changed' => 'Company address changed',
];
