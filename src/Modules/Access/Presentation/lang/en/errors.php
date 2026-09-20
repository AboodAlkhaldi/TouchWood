<?php

declare(strict_types=1);

// Access's error messages, by error type (access.{key}).
return [
    'invalid_access_attribute' => [
        'title' => 'Invalid details',
        'detail' => 'The :attribute is not valid.',
    ],
    'role_not_found' => [
        'title' => 'Role not found',
        'detail' => 'There is no such role.',
    ],
    'staff_not_found' => [
        'title' => 'Staff member not found',
        'detail' => 'There is no such staff member.',
    ],
    'unknown_permission' => [
        'title' => 'Unknown action',
        'detail' => '":permission" is not an action a role can hold.',
    ],
    'reserved_permission' => [
        'title' => 'Super Admins only',
        'detail' => '":permission" belongs to Super Admins only and cannot be put in a role.',
    ],
    'admin_only_permission' => [
        'title' => 'Admin roles only',
        'detail' => '":permission" is a management action and can be put only in an admin role.',
    ],
    'permission_escalation' => [
        'title' => 'More than you hold',
        'detail' => 'You cannot give ":permission" there: you do not hold it in every store it would reach.',
    ],
    'role_name_taken' => [
        'title' => 'Name already used',
        'detail' => 'Another saved role is already called ":name".',
    ],
    'role_in_use' => [
        'title' => 'Role in use',
        'detail' => 'This role is held by :count staff member(s): :holders. Pick a replacement role for them first.',
    ],
    'staff_not_editable' => [
        'title' => 'This staff member cannot be changed here',
        'detail' => 'Super Admins are managed only on the server, admins only by a Super Admin, and nobody changes their own access.',
    ],
    'super_admin_only' => [
        'title' => 'Super Admins only',
        'detail' => 'Only a Super Admin can create, change or assign an admin role.',
    ],
    'staff_email_in_use' => [
        'title' => 'Email already used',
        'detail' => 'Another account already uses this email.',
    ],
    'phone_already_in_use' => [
        'title' => 'Phone number already used',
        'detail' => 'Another account already uses this phone number.',
    ],
    'customer_blocked' => [
        'title' => 'Account blocked',
        'detail' => 'Your account is blocked — please contact us.',
    ],
    'email_already_registered' => [
        'title' => 'You already have an account',
        'detail' => 'You already have an account — please sign in.',
    ],
    'customer_not_found' => [
        'title' => 'Account not found',
        'detail' => 'We could not find this account.',
    ],
    'invalid_or_expired_link' => [
        'title' => 'Link not valid',
        'detail' => 'This link is not valid or has expired. Ask for a new one.',
    ],
    'invalid_code' => [
        'title' => 'Wrong code',
        'detail' => 'The code is wrong or has expired.',
    ],
    'code_request_too_soon' => [
        'title' => 'Please wait',
        'detail' => 'A new code can be sent in :seconds seconds.',
    ],
    'password_too_weak' => [
        'title' => 'Choose another password',
        'detail' => 'Use at least :min characters, and a password that has not appeared in a data breach.',
    ],
    'last_super_admin' => [
        'title' => 'The last Super Admin',
        'detail' => 'At least one active Super Admin must remain.',
    ],
    'invalid_staff_status' => [
        'title' => 'Not possible now',
        'detail' => 'This cannot be done while the staff member is in this state.',
    ],
    'invalid_credentials' => [
        'title' => 'Could not sign in',
        'detail' => 'Wrong email or password.',
    ],
    'account_locked' => [
        'title' => 'Too many attempts',
        'detail' => 'Too many wrong passwords. Try again in :minutes minutes.',
    ],
    'sign_in_refused' => [
        'title' => 'Could not sign in',
        'detail' => 'This account cannot sign in. Please contact an administrator.',
    ],
];
