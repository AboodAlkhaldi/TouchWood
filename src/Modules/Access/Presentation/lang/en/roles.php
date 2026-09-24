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

    'new' => 'New role',
    'edit' => 'Edit',
    'clone' => 'Clone',
    'delete' => 'Delete',
    'refresh' => 'Refresh permissions',
    'save' => 'Save the role',
    'cancel' => 'Cancel',

    // D1's table: business areas down the side, roles across the top.
    'reaches' => 'Reaches into it',
    'does_not_reach' => 'Does not',
    'filter_areas' => 'Filter the areas',
    'columns' => 'Roles: :shown of :total',
    'columns_hint' => 'Roles to show',
    'no_areas' => 'No business area matches that.',
    'comparison' => 'Permissions by role',
    'comparison_hint' => 'Which areas each role reaches into.',

    // D2.
    'actions' => 'What it allows',
    'holders' => 'Who holds it',
    'holders_hint' => 'Only the people you manage are listed; the count is everyone.',
    'no_holders' => 'Nobody holds this role.',
    'every_store' => 'Every store',
    'store_free' => 'Every store, by its nature',
    'not_editable' => 'Only a Super Admin may change an admin role.',

    // D3.
    'new_title' => 'A new role',
    'edit_title' => 'Change a role',
    'chosen_count' => ':count chosen',
    'not_yours' => 'You do not hold this action, so you cannot give it.',
    'holders_warning' => 'Changing this role changes it for the :count people who hold it.',
    'level_locked' => "A role's level cannot change after it is made.",

    // D4.
    'delete_title' => 'Delete this role',
    'delete_question' => 'Everyone holding it must move to another role of the same level.',
    'replacement' => 'Move them to',
    'delete_confirm' => 'Delete the role',
    'delete_none_left' => 'There is no other role of this level to move them to.',

    // After the fact.
    'created' => 'The role was created.',
    'saved' => 'The role was saved.',
    'cloned' => 'The role was copied.',
    'deleted' => 'The role was deleted.',
    'refreshed' => "Everyone's permissions were rebuilt.",
];
