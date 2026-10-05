<?php

declare(strict_types=1);

// The staff screens (frontend.md §3.3, C1–C9). What a person may do to somebody else is Access's
// answer; these are only the words around it.
return [
    'title' => 'Staff',
    'subtitle' => 'Who works here, and what each of them may do.',

    // C1.
    // Shown only to a Super Admin, above the admins (amendment 54).
    'super_admins' => 'Super Admins',
    // How a Super Admin is named to anyone but another Super Admin (amendment 54).
    'system_administrator' => 'System administrator',
    // A revoked Super Admin, in the Super Admins section (amendment 57).
    'former_super_admin' => 'Former Super Admin',
    'admins' => 'Admins',
    'centralized' => 'Centralized',
    'search' => 'Search by Name or Email',
    'status' => 'Status',
    'all_statuses' => 'Any Status',
    'status_active' => 'Active',
    'status_invited' => 'Invited',
    'status_disabled' => 'Disabled',
    // Final: an invitation that was cancelled is not sent again (access.md amendment 29).
    'status_cancelled' => 'Cancelled',
    // Before Geist's Relative Time Card, which writes "5h ago" or a date: "Joined 5h ago",
    // "Joined Mar 14, 2026" (shadcn rebuild; "Since" read wrongly before a relative time).
    'since' => 'Joined :date',
    'invited_on' => 'Invited :date',
    // Geist's Empty State: the blank slate names the next action; a filtered list that finds
    // nobody says so, quoting a typed search, and offers to clear the filters (shadcn rebuild).
    'no_staff' => 'Invite a member to give them a way into the panel.',
    'no_match_title' => 'No Staff Members Match Your Filters',
    'no_match_query' => 'No staff members match “:query”. Clear the filters to see everyone.',
    'no_match' => 'Widen or clear the filters to see everyone.',
    'clear_filters' => 'Clear Filters',
    // Geist's Search Input: a scoped placeholder.
    'search_placeholder' => 'Search staff',
    'invite' => 'Invite Member',
    'total' => ':count people',

    // C2.
    'profile' => 'Profile',
    'job_title' => 'Job Title',
    'email' => 'Work Email',
    'phone' => 'Phone',
    'date_of_birth' => 'Date of Birth',
    'country' => 'Country',
    'countries_ours' => 'Where We Have Stores',
    'countries_all' => 'Every Country',
    // The country picker's search (Geist's Combobox): a scoped placeholder, and the typed text quoted
    // when nothing matches.
    'country_search' => 'Search countries',
    'country_none' => 'No countries match “:query”.',
    'address' => 'Address',
    'communication_language' => 'Communication Language',
    'communication_language_hint' => 'The language their emails and codes are written in.',
    'role' => 'Role',
    'no_role' => 'No role yet.',
    'stores' => 'Stores',
    'every_store' => 'Every store',
    'allows' => 'What It Allows',
    'store_free' => 'Every store, by its nature',
    'exception' => 'Custom Stores',
    'exception_hint' => 'This action works in only some of the stores the role reaches.',
    'super_admin' => 'Super Admin',
    'super_admin_hint' => 'Super Admins are made and removed by console command only.',
    'yourself' => 'This is your own account. Change it in Account & settings.',

    // C4–C9.
    'edit_profile' => 'Edit Profile',
    'change_email' => 'Change Email',
    'change_email_hint' => 'The change happens when the link sent to the new address is used.',
    'new_email' => 'New Email',
    'change_role' => 'Change Role and Stores',
    'disable' => 'Disable Member',
    'disable_hint' => 'Their sessions and trusted browsers end at once.',
    'enable' => 'Enable Member',
    'resend_invitation' => 'Resend Invitation',
    'resend_invitation_hint' => 'The earlier link stops working.',
    'cancel_invitation' => 'Cancel Invitation',
    'save' => 'Save Changes',
    'cancel' => 'Cancel',

    // After the fact.
    'profile_saved' => 'Profile saved',
    'email_link_sent' => 'Link sent to the new address',
    'disabled' => 'Member disabled and signed out everywhere',
    'enabled' => 'Member enabled',
    'invitation_resent' => 'Invitation resent',
    'invitation_cancelled' => 'Invitation cancelled',
    // C6 - one person's role and stores.
    'role_saved' => 'Role and stores saved',
    'personal_role_name' => ":name's role",
    'pick_role' => 'Role',
    'pick_role_hint' => 'Pick a saved role, or edit one into a role of their own.',
    // Past six saved roles the cards give way to a searchable picker (Geist's Choicebox; owner,
    // 2026-10-03).
    'saved_role' => 'Saved Role',
    'choose_saved_role' => 'Choose a saved role',
    'role_search' => 'Search roles',
    'role_none' => 'No roles match “:query”.',
    'own_role' => 'A Role of Their Own',
    'own_role_hint' => 'Editing a saved role here does not change it for anybody else holding it: it becomes theirs alone.',
    'edited' => 'Edited',
    'actions_count' => ':count actions',
    'where' => 'Where It Reaches',
    'where_hint' => 'The role says what they may do, and these stores say where: no action reaches beyond them.',
    'stores_all' => 'Every Store',
    'stores_selected' => 'Selected Stores',
    'no_stores_to_give' => 'You can only hand out stores you manage yourself.',
    // Each action's stores, for a reach of two or more stores or every store (access.md amendment 59).
    'exceptions_title' => 'Each Action\'s Stores',
    'exceptions_hint' => 'Every action works in all the stores above, unless you choose some of them for it.',
    'exceptions_all' => 'All Selected Stores',
    'exceptions_custom' => 'Custom',
    'exceptions_custom_stores' => 'Stores for :action',
    'exceptions_cut' => 'Taken out, no longer in Where It Reaches: :stores.',
    'exceptions_emptied' => 'Choose at least one store, or All Selected Stores.',
    'exceptions_empty' => 'Couldn\'t save the stores: none is chosen for :actions. Choose at least one for each, or All Selected Stores.',
    // In place of an empty list, when no chosen action works store by store (Geist's Empty State).
    'exceptions_none_title' => 'No Actions Work Store by Store',
    'exceptions_none' => 'Every action chosen reaches every store by its nature.',
    'back_to' => 'Back to :name',
    'save_role' => 'Save Role',

    // C3 - the invitation.
    'invite_title' => 'Invite Member',
    'invite_subtitle' => 'Who they are, what they may do, and where.',
    'step_profile' => 'Profile',
    'step_role' => 'Role',
    'step_stores' => 'Stores',
    'step_of' => 'Step :step of :total',
    'nothing_sent_yet' => 'Nothing is sent until the last step.',
    'next' => 'Next Step',
    'back' => 'Previous Step',
    'send_invitation' => 'Send Invitation',
    'invitation_sent' => 'Invitation sent',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'as_admin' => 'An Admin',
    'as_admin_hint' => 'Admins are not tied to a store, and only a Super Admin may bring one in.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Staff Members',
    'no_role_title' => 'Nothing Allowed Yet',
    'verification_label' => 'member name',
    'cancel_invitation_body' => 'The link in the invitation stops working. A new invitation can still be sent.',
    'keep_invitation' => 'Keep Invitation',
];
