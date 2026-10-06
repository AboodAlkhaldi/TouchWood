<?php

declare(strict_types=1);

// Access's error messages, by error type (access.{key}).
return [
    'invalid_access_attribute' => [
        'title' => 'Invalid Details',
        'detail' => 'Couldn\'t save: the :attribute isn\'t valid. Check it and try again.',
    ],
    'invalid_address' => [
        'title' => 'Check the Address',
        'detail' => 'Couldn\'t save the address: the :field isn\'t valid for this country. Check it and try again.',
    ],
    'address_not_found' => [
        'title' => 'Address Not Found',
        'detail' => 'Couldn\'t find this address in your address book. Reload the page.',
    ],
    'address_format_missing' => [
        'title' => 'Addresses Are Not Ready',
        'detail' => 'Failed to find an address form for this store. Try again later.',
    ],
    'invalid_customer_status' => [
        'title' => 'Nothing to Change',
        'detail' => 'Couldn\'t make that change: this account\'s state doesn\'t allow it. Reload the page.',
    ],
    'too_many_addresses' => [
        'title' => 'Address Book Full',
        'detail' => 'Couldn\'t add the address: you can keep :limit in this store. Delete one to add another.',
    ],
    'role_not_found' => [
        'title' => 'Role Not Found',
        'detail' => 'Couldn\'t find this role. Go back to the roles list.',
    ],
    'staff_not_found' => [
        'title' => 'Staff Member Not Found',
        'detail' => 'Couldn\'t find this staff member. Go back to the staff list.',
    ],
    'unknown_permission' => [
        'title' => 'Unknown Action',
        'detail' => 'Couldn\'t save the role: ":permission" isn\'t an action a role can hold. Remove it and try again.',
    ],
    'reserved_permission' => [
        'title' => 'Super Admins Only',
        'detail' => 'Couldn\'t save the role: ":permission" belongs to Super Admins only. Remove it and try again.',
    ],
    'admin_only_permission' => [
        'title' => 'Admin Roles Only',
        'detail' => 'Couldn\'t save the role: ":permission" is a management action, for admin roles only. Remove it, or make this an admin role.',
    ],
    'action_stores_beyond_reach' => [
        'title' => 'Outside Where It Reaches',
        'detail' => 'Couldn\'t save the role: ":permission" is given stores outside Where It Reaches. Choose its stores among those, or add the store to Where It Reaches.',
    ],
    'permission_escalation' => [
        'title' => 'More Than You Hold',
        'detail' => 'Couldn\'t give ":permission" there: you don\'t hold it in every store it would reach. Ask someone who does.',
    ],
    'role_name_taken' => [
        'title' => 'Name Already Used',
        'detail' => 'Couldn\'t save the role: another role is already called ":name". Choose another name.',
    ],
    'role_in_use' => [
        'title' => 'Role in Use',
        'detail' => 'Couldn\'t delete the role: :count staff member(s) hold it (:holders). Pick a replacement role for them first.',
    ],
    'staff_not_editable' => [
        'title' => 'Can\'t Be Changed Here',
        'detail' => 'Couldn\'t change this staff member: Super Admins are managed on the server, admins by a Super Admin, and nobody changes their own access. Ask a Super Admin.',
    ],
    'super_admin_only' => [
        'title' => 'Super Admins Only',
        'detail' => 'Couldn\'t do that: only a Super Admin creates, changes or assigns an admin role. Ask a Super Admin.',
    ],
    'staff_email_in_use' => [
        'title' => 'Email Already Used',
        'detail' => 'Couldn\'t use this email: another account already has it. Enter a different email.',
    ],
    'phone_already_in_use' => [
        'title' => 'Phone Number Already Used',
        'detail' => 'Couldn\'t use this phone number: another account already has it. Enter a different number.',
    ],
    'customer_blocked' => [
        'title' => 'Account Blocked',
        'detail' => 'Couldn\'t continue: your account is blocked. Contact us.',
    ],
    'email_already_registered' => [
        'title' => 'You Already Have an Account',
        'detail' => 'Couldn\'t create the account: this email already has one. Sign in instead.',
    ],
    'customer_not_found' => [
        'title' => 'Account Not Found',
        'detail' => 'Couldn\'t find this account. Check the details and try again.',
    ],
    'invalid_or_expired_link' => [
        'title' => 'Link Not Valid',
        'detail' => 'Couldn\'t use this link: it isn\'t valid or has expired. Ask for a new one.',
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
        'detail' => 'Couldn\'t use this password: it needs at least :min characters and must not have appeared in a data breach. Choose another.',
    ],
    'last_super_admin' => [
        'title' => 'The Last Super Admin',
        'detail' => 'Couldn\'t do that: it would leave no active Super Admin. Keep at least one.',
    ],
    'invalid_staff_status' => [
        'title' => 'Not Possible Now',
        'detail' => 'Couldn\'t do that while the staff member is in this state. Reload the page.',
    ],
    'invalid_credentials' => [
        'title' => 'Couldn\'t Sign In',
        'detail' => 'Couldn\'t sign in. Check your email and password.',
    ],
    'account_locked' => [
        'title' => 'Too Many Attempts',
        'detail' => 'Couldn\'t sign in: too many wrong passwords. Try again in :minutes minutes.',
    ],
    'too_many_requests' => [
        'title' => 'Too Many Attempts',
        'detail' => 'Couldn\'t continue: too many requests from this connection. Try again in :minutes minutes.',
    ],
    'sign_in_refused' => [
        'title' => 'Couldn\'t Sign In',
        'detail' => 'Couldn\'t sign in: this account can\'t sign in. Contact an administrator.',
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
