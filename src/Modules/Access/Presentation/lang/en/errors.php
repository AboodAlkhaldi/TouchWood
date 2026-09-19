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
];
