<?php

declare(strict_types=1);

// Payments' settings as the settings page names them (PaymentsSettings, payments.md §5).
return [
    'module' => 'Payments',

    'timers.window_minutes' => 'Time on the Bank\'s Page (Minutes)',
    'timers.wait_minutes' => 'Wait for the Bank\'s Answer After It (Minutes)',
    'timers.hold_minutes' => 'Hold Limit for an Unanswered Payment (Minutes)',
    'transfer.check_hours' => 'Hours to Check a Bank Transfer',
    'bank.iban' => 'IBAN Companies Transfer To',
    'bank.name' => 'Bank Name',
    'bank.holder' => 'Account Holder',

    // The section's line (PaymentsSettingsLine, §1.2, gap 8.8): the two parts, joined with " · ".
    'line.online.off' => 'Online payment: off — individuals pay through staff until a gateway is set up',
    'line.online.test' => 'Online payment: TEST mode — no real money is taken',
    'line.online.on' => 'Online payment: on',
    'line.online.key_rejected' => 'Online payment: stopped — MyFatoorah refused the key; individuals pay through staff until a new one is saved',
    'line.bank_transfer.on' => 'Bank transfer: on',
    'line.bank_transfer.off' => 'Bank transfer: temporarily off — fill in all three to turn it on.',
];
