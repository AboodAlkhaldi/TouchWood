<?php

declare(strict_types=1);

// What each audited Ops action is called, for the audit log (frontend.md 3.5, E6). An action with
// no line here is shown exactly as it is recorded.
return [
    'notice.created' => 'Offer notice written',
    'notice.changed' => 'Offer notice changed',
    'notice.cancelled' => 'Offer notice cancelled',
    'staff_message.sent' => 'Message sent to customers',
];
