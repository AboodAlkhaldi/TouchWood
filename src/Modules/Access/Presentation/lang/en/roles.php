<?php

declare(strict_types=1);

// The roles screens (frontend.md §3.4, D1–D5). A role is a set of actions with a name; which stores
// it reaches is chosen per staff member, not here.
return [
    'title' => 'Roles',
    'subtitle' => 'What each kind of staff member may do.',

    'name' => 'Name',
    'name_ar' => 'Name in Arabic',
    'name_en' => 'Name in English',
    'level' => 'Level',
    'level_admin' => 'Admin',
    'level_staff' => 'Staff',
    'actions_count' => ':count actions',
    'holders_count' => ':count people',
    'no_roles' => 'No roles yet.',

    'new' => 'Create Role',
    'edit' => 'Edit Role',
    'clone' => 'Clone Role',
    'delete' => 'Delete Role',
    'refresh' => 'Refresh Permissions',
    'save' => 'Save Role',
    'cancel' => 'Cancel',

    // D1's table: business areas down the side, roles across the top.
    'reaches' => 'Reaches into it',
    'does_not_reach' => 'Does not',
    'filter_areas' => 'Filter the areas',
    'columns' => 'Roles: :shown of :total',
    'columns_hint' => 'Roles to Show',
    'no_areas' => 'No business area matches that.',
    'comparison' => 'Permissions by Role',
    'comparison_hint' => 'Which areas each role reaches into.',

    // D2.
    'actions' => 'What It Allows',
    'holders' => 'Who Holds It',
    'holders_hint' => 'Only the people you manage are listed; the count is everyone.',
    'no_holders' => 'Nobody holds this role.',
    'every_store' => 'Every store',
    'store_free' => 'Every store, by its nature',
    'not_editable' => 'Only a Super Admin may change an admin role.',

    // D3.
    'new_title' => 'Create Role',
    'edit_title' => 'Edit Role',
    'chosen_count' => ':count chosen',
    'not_yours' => 'You do not hold this action, so you cannot give it.',
    'holders_warning' => 'Changing this role changes it for the :count people who hold it.',
    'level_locked' => "A role's level cannot change after it is made.",

    // D4.
    'delete_title' => 'Delete Role',
    'delete_question' => 'Everyone holding it must move to another role of the same level.',
    'replacement' => 'Replacement Role',
    'delete_confirm' => 'Delete Role',
    'delete_none_left' => 'There is no other role of this level to move them to.',

    // After the fact.
    'created' => 'Role created',
    'saved' => 'Role saved',
    'cloned' => 'Role cloned',
    'deleted' => 'Role deleted',
    'refreshed' => 'Permissions refreshed for everyone holding it',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Roles Yet',
    'no_areas_title' => 'No Areas Match',
    'no_holders_title' => 'No Holders',
    'verification_label' => 'role name',
    'delete_body' => 'The role :name will be permanently deleted.',
];
