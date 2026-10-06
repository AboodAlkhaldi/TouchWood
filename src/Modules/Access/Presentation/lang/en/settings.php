<?php

declare(strict_types=1);

// The names of Access's settings, as the settings screen shows them (frontend.md 3.5, E4).
//
// They sit in one Access section although they carry two permissions (owner, 2026-09-22): a store's
// own customer numbers are ordinary, and the staff sign-in and security numbers are admin-only.
return [
    'module' => 'Access',

    // Staff: global, and admin-only.
    'staff.password_min_length' => 'Shortest Password',
    'staff.invitation_hours' => 'An Invitation Lasts (Hours)',
    'staff.super_admin_invitation_hours' => 'A Super Admin\'s Invitation Lasts (Hours)',
    'staff.email_change_hours' => 'An Email-Change Link Lasts (Hours)',
    'staff.sms_code_length' => 'Digits in a Code',
    'staff.sms_code_minutes' => 'A Code Lasts (Minutes)',
    'staff.sms_resend_seconds' => 'Wait Before Sending Another (Seconds)',
    'staff.sms_codes_per_hour' => 'Codes an Hour',
    'staff.sms_code_attempts' => 'Tries at a Code',
    'staff.lockout_attempts' => 'Wrong Passwords Before an Account Waits',
    'staff.lockout_minutes' => 'How Long It Waits (Minutes)',
    'staff.ip_attempts' => 'Wrong Passwords from One Address',
    'staff.ip_minutes' => 'How Long That Address Waits (Minutes)',
    'staff.session_idle_minutes' => 'Signed Out After Doing Nothing (Minutes)',
    'staff.session_max_hours' => 'Longest a Session Lasts (Hours)',
    'staff.trusted_browser_hours' => 'A Trusted Browser Is Remembered (Hours)',
    'staff.password_reset_minutes' => 'A Reset Link Lasts (Minutes)',
    'staff.password_reset_hourly_limit' => 'Reset Emails an Hour',

    // Customers: one store's own.
    'customer.password_min_length' => 'Shortest Password',
    'customer.email_verification_hours' => 'A Verification Link Lasts (Hours)',
    'customer.sms_code_length' => 'Digits in a Code',
    'customer.sms_code_minutes' => 'A Code Lasts (Minutes)',
    'customer.sms_resend_seconds' => 'Wait Before Sending Another (Seconds)',
    'customer.sms_codes_per_hour' => 'Codes an Hour',
    'customer.sms_code_attempts' => 'Tries at a Code',
    'customer.lockout_attempts' => 'Wrong Passwords Before an Account Waits',
    'customer.lockout_minutes' => 'How Long It Waits (Minutes)',
    'customer.ip_attempts' => 'Wrong Passwords from One Address',
    'customer.ip_minutes' => 'How Long That Address Waits (Minutes)',
    'customer.session_idle_minutes' => 'Signed Out After Doing Nothing (Minutes)',
    'customer.remember_days' => '"Keep Me Signed In" Lasts (Days)',
    'customer.password_reset_minutes' => 'A Reset Link Lasts (Minutes)',
    'customer.password_reset_hourly_limit' => 'Reset Emails an Hour',
    'customer.address_requests_per_hour' => 'Address Changes an Hour',
    'customer.addresses_per_store' => 'Addresses Kept per Store',
    'customer.terms_version' => 'Version of the Terms in Force',
];
