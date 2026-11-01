<?php

declare(strict_types=1);

// Payments' settings as the settings page names them (PaymentsSettings, payments.md §5).
return [
    'module' => 'Payments',

    'timers.window_minutes' => 'Time on the Bank\'s Page (Minutes)',
    'timers.wait_minutes' => 'Wait for the Bank\'s Answer After It (Minutes)',
    'timers.hold_minutes' => 'Hold Limit for an Unanswered Payment (Minutes)',
    'transfer.check_hours' => 'Hours to Check a Bank Transfer',
];
