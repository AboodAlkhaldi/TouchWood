<?php

declare(strict_types=1);

// The names of Access's settings, as the settings screen shows them (frontend.md 3.5, E4).
//
// They sit in one Access section although they carry two permissions (owner, 2026-09-22): a store's
// own customer numbers are ordinary, and the staff sign-in and security numbers are admin-only.
return [
    'module' => 'Access',

    // Staff: global, and admin-only.
    'staff.password_min_length' => 'Shortest password',
    'staff.invitation_hours' => 'An invitation lasts (hours)',
    'staff.super_admin_invitation_hours' => 'A Super Admin\'s invitation lasts (hours)',
    'staff.email_change_hours' => 'An email-change link lasts (hours)',
    'staff.sms_code_length' => 'Digits in a code',
    'staff.sms_code_minutes' => 'A code lasts (minutes)',
    'staff.sms_resend_seconds' => 'Wait before sending another (seconds)',
    'staff.sms_codes_per_hour' => 'Codes an hour',
    'staff.sms_code_attempts' => 'Tries at a code',
    'staff.lockout_attempts' => 'Wrong passwords before an account waits',
    'staff.lockout_minutes' => 'How long it waits (minutes)',
    'staff.ip_attempts' => 'Wrong passwords from one address',
    'staff.ip_minutes' => 'How long that address waits (minutes)',
    'staff.session_idle_minutes' => 'Signed out after doing nothing (minutes)',
    'staff.session_max_hours' => 'Longest a session lasts (hours)',
    'staff.trusted_browser_days' => 'A trusted browser is remembered (days)',
    'staff.password_reset_minutes' => 'A reset link lasts (minutes)',
    'staff.password_reset_hourly_limit' => 'Reset emails an hour',

    // Customers: one store's own.
    'customer.password_min_length' => 'Shortest password',
    'customer.email_verification_hours' => 'A verification link lasts (hours)',
    'customer.sms_code_length' => 'Digits in a code',
    'customer.sms_code_minutes' => 'A code lasts (minutes)',
    'customer.sms_resend_seconds' => 'Wait before sending another (seconds)',
    'customer.sms_codes_per_hour' => 'Codes an hour',
    'customer.sms_code_attempts' => 'Tries at a code',
    'customer.lockout_attempts' => 'Wrong passwords before an account waits',
    'customer.lockout_minutes' => 'How long it waits (minutes)',
    'customer.ip_attempts' => 'Wrong passwords from one address',
    'customer.ip_minutes' => 'How long that address waits (minutes)',
    'customer.session_idle_minutes' => 'Signed out after doing nothing (minutes)',
    'customer.remember_days' => '"Keep me signed in" lasts (days)',
    'customer.password_reset_minutes' => 'A reset link lasts (minutes)',
    'customer.password_reset_hourly_limit' => 'Reset emails an hour',
    'customer.address_requests_per_hour' => 'Address changes an hour',
    'customer.addresses_per_store' => 'Addresses kept per store',
    'customer.terms_version' => 'Version of the terms in force',
];
