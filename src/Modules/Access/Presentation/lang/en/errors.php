<?php

declare(strict_types=1);

// Access's error messages, by error type (access.{key}).
return [
    'invalid_access_attribute' => [
        'title' => 'Invalid Details',
        'detail' => 'The :attribute is not valid.',
    ],
    'invalid_address' => [
        'title' => 'Check the Address',
        'detail' => 'The :field is not valid for this country.',
    ],
    'address_not_found' => [
        'title' => 'Address Not Found',
        'detail' => 'This address is no longer in your address book.',
    ],
    'address_format_missing' => [
        'title' => 'Addresses Are Not Ready',
        'detail' => 'This store has no address form yet. Try again later.',
    ],
    'invalid_customer_status' => [
        'title' => 'Nothing to Change',
        'detail' => 'This account is not in a state that allows that change.',
    ],
    'too_many_addresses' => [
        'title' => 'Address Book Full',
        'detail' => 'You can keep :limit addresses in this store. Delete one to add another.',
    ],
    'role_not_found' => [
        'title' => 'Role Not Found',
        'detail' => 'There is no such role.',
    ],
    'staff_not_found' => [
        'title' => 'Staff Member Not Found',
        'detail' => 'There is no such staff member.',
    ],
    'unknown_permission' => [
        'title' => 'Unknown Action',
        'detail' => '":permission" is not an action a role can hold.',
    ],
    'reserved_permission' => [
        'title' => 'Super Admins Only',
        'detail' => '":permission" belongs to Super Admins only and cannot be put in a role.',
    ],
    'admin_only_permission' => [
        'title' => 'Admin Roles Only',
        'detail' => '":permission" is a management action and can be put only in an admin role.',
    ],
    'permission_escalation' => [
        'title' => 'More Than You Hold',
        'detail' => 'You cannot give ":permission" there: you do not hold it in every store it would reach.',
    ],
    'role_name_taken' => [
        'title' => 'Name Already Used',
        'detail' => 'Another saved role is already called ":name".',
    ],
    'role_in_use' => [
        'title' => 'Role in Use',
        'detail' => 'This role is held by :count staff member(s): :holders. Pick a replacement role for them first.',
    ],
    'staff_not_editable' => [
        'title' => 'This Staff Member Cannot Be Changed Here',
        'detail' => 'Super Admins are managed only on the server, admins only by a Super Admin, and nobody changes their own access.',
    ],
    'super_admin_only' => [
        'title' => 'Super Admins Only',
        'detail' => 'Only a Super Admin can create, change or assign an admin role.',
    ],
    'staff_email_in_use' => [
        'title' => 'Email Already Used',
        'detail' => 'Another account already uses this email.',
    ],
    'phone_already_in_use' => [
        'title' => 'Phone Number Already Used',
        'detail' => 'Another account already uses this phone number.',
    ],
    'customer_blocked' => [
        'title' => 'Account Blocked',
        'detail' => 'Your account is blocked. Contact us.',
    ],
    'email_already_registered' => [
        'title' => 'You Already Have an Account',
        'detail' => 'You already have an account. Sign in instead.',
    ],
    'customer_not_found' => [
        'title' => 'Account Not Found',
        'detail' => 'Couldn\'t find this account.',
    ],
    'invalid_or_expired_link' => [
        'title' => 'Link Not Valid',
        'detail' => 'This link is not valid or has expired. Ask for a new one.',
    ],
    'invalid_code' => [
        'title' => 'Wrong Code',
        'detail' => 'Couldn\'t verify the code: it is wrong or has expired. Try again, or ask for a new one.',
    ],
    // Said to somebody who is trying to get in, not to somebody pressing "send another code": a
    // sign-in straight after a password reset meets the same limit. So it names the way forward -
    // the code already sent - rather than only the wait (owner, 2026-09-22).
    // It has to stay true for both limits behind it: the gap between codes, where the earlier code
    // is certainly still alive, and the hourly limit, where it may have expired. Hence "if you
    // still have it".
    'code_request_too_soon' => [
        'title' => 'You Already Have a Code',
        'detail' => 'A code was already sent to your phone - enter it if you still have it. A new one can be sent in :seconds seconds.',
    ],
    'password_too_weak' => [
        'title' => 'Choose Another Password',
        'detail' => 'Use at least :min characters, and a password that has not appeared in a data breach.',
    ],
    'last_super_admin' => [
        'title' => 'The Last Super Admin',
        'detail' => 'At least one active Super Admin must remain.',
    ],
    'invalid_staff_status' => [
        'title' => 'Not Possible Now',
        'detail' => 'This cannot be done while the staff member is in this state.',
    ],
    'invalid_credentials' => [
        'title' => 'Couldn\'t Sign In',
        'detail' => 'Couldn\'t sign in. Check your email and password.',
    ],
    'account_locked' => [
        'title' => 'Too Many Attempts',
        'detail' => 'Too many wrong passwords. Try again in :minutes minutes.',
    ],
    'too_many_requests' => [
        'title' => 'Too Many Attempts',
        'detail' => 'Too many requests from this connection. Try again in :minutes minutes.',
    ],
    'sign_in_refused' => [
        'title' => 'Couldn\'t Sign In',
        'detail' => 'This account cannot sign in. Contact an administrator.',
    ],
    /*
    | Field names, for the refusals that name one (":attribute", ":field").
    |
    | A refusal carries the field's key, because that is what the domain calls it and what the form
    | posted. These are the same names written for a person to read.
    */
    'fields' => [
        'account_type' => 'account type',
        'address' => 'address',
        'avatar' => 'picture',
        'country' => 'country',
        'current_password' => 'current password',
        'date_of_birth' => 'date of birth',
        'email' => 'email address',
        'exceptions' => 'actions with stores of their own',
        'invited_by' => 'inviter',
        'latitude' => 'latitude',
        'level' => 'level',
        'locale' => 'communication language',
        'longitude' => 'longitude',
        'map_pin' => 'map pin',
        'name' => 'name',
        'password' => 'password',
        'permissions' => 'actions',
        'phone' => 'mobile number',
        'reason' => 'reason',
        'replacement' => 'replacement role',
        'role' => 'role',
        'session_version' => 'session',
        'status' => 'status',
        'store' => 'store',
        'stores' => 'stores',
        'terms' => 'terms',
        'topic' => 'topic',
    ],
];
