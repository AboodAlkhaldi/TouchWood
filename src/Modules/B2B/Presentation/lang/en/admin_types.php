<?php

declare(strict_types=1);

// The types page (b2b.md §1.3, §4.6, amendment 19): one store's company types and document types.
// Geist's writing rules (frontend.md §1.10); every word here is the builder's, provisional until the
// owner confirms it (amendment 19(k)).
return [
    'title' => [
        'company' => 'Company Types',
        'document' => 'Document Types',
    ],
    'subtitle' => [
        'company' => 'The legal forms a company in :store chooses from when it applies.',
        'document' => 'The papers a company in :store attaches when it applies.',
    ],
    'tabs' => 'Type lists',

    'copied' => [
        'label' => 'Starting Lists',
        'body' => 'These are the lists every store starts with, written for Saudi forms and papers. Change what this store needs, or mark the lists reviewed.',
        'button' => 'Mark Lists Reviewed',
    ],

    'column' => [
        'position' => 'Position',
        'name_ar' => 'Arabic Name',
        'name_en' => 'English Name',
        'status' => 'Status',
        'holders' => 'Companies',
        'required' => 'Required',
        'actions' => 'Actions',
    ],
    'state' => [
        'active' => 'Active',
        'HIDDEN' => 'Hidden',
        'GREYED' => 'Greyed Out',
    ],
    'required' => [
        'yes' => 'Required',
        'no' => 'Optional',
    ],
    'row_actions' => 'Open actions for :name',
    'empty' => [
        'company' => 'No Company Types Yet',
        'document' => 'No Document Types Yet',
        'add' => 'Add the first one to offer it to new applications.',
        'none' => 'Nothing has been added to this list for this store yet.',
    ],

    'field' => [
        'name_ar' => 'Arabic Name',
        'name_en' => 'English Name',
        'position' => 'Position',
        'position_helper' => 'From 0 to 10,000, lowest first; two types may share one, and the English name then decides.',
        'required' => 'Required Paper',
        'required_helper' => 'A company must attach it to send its application.',
    ],

    'list' => [
        'company' => [
            'add' => 'Add Company Type',
            'add_body' => 'New applications can choose it at once.',
            'rename' => 'Rename…',
            'rename_title' => 'Rename Company Type',
            'rename_body' => 'Every screen shows the new name, applications already sent included.',
            'move' => 'Change Position…',
            'move_title' => 'Change Position',
            'move_body' => 'Its place in the list companies choose from.',
            'deactivate' => 'Deactivate Company Type…',
            'deactivate_title' => 'Deactivate Company Type',
            'deactivate_body' => 'New applications can no longer choose :name. Applications already sent keep it.',
            'activate' => 'Activate Company Type',
            'transfer' => 'Move Companies…',
            'transfer_title' => 'Move Companies',
            'transfer_body' => 'Every company holding :name moves to the type you choose. Both types stay offered.',
            'transfer_target' => 'Move To',
            'transfer_none' => 'No company holds this type.',
            'choose' => 'Select a company type',
        ],
        'document' => [
            'add' => 'Add Document Type',
            'add_body' => 'New applications ask for it at once.',
            'rename' => 'Rename…',
            'rename_title' => 'Rename Document Type',
            'rename_body' => 'Every screen shows the new name, applications already sent included.',
            'move' => 'Change Position…',
            'move_title' => 'Change Position',
            'move_body' => 'Its place in the list of papers on the form.',
            'deactivate' => 'Deactivate Document Type…',
            'deactivate_title' => 'Deactivate Document Type',
            'deactivate_body' => 'New applications can no longer attach :name. Applications already sent keep their papers.',
            'activate' => 'Activate Document Type',
            'make_required' => 'Make Required',
            'make_optional' => 'Make Optional',
        ],
    ],

    'shown' => [
        'legend' => 'How It Shows',
        'HIDDEN' => 'Hidden',
        'GREYED' => 'Greyed Out',
        'helper' => 'Hidden leaves it off the form, while greyed out keeps it there where it can\'t be chosen.',
    ],
    'holders' => [
        'legend' => 'Companies Holding It',
        'count' => 'Companies holding it now: :count.',
        'leave' => 'Leave Them on This Type',
        'replace' => 'Move Them to Another Type',
        'new' => 'Move Them to a New Type',
        'suspended' => 'Suspended companies keep this type.',
        'replacement' => 'Replacement Type',
        'new_position_helper' => 'Leave it empty to use this type\'s position.',
    ],

    'toast' => [
        'company' => [
            'added' => 'Company type added',
            'renamed' => 'Company type renamed',
            'moved' => 'Position changed',
            'deactivated' => 'Company type deactivated',
            'activated' => 'Company type activated',
            'transferred' => 'Companies moved',
        ],
        'document' => [
            'added' => 'Document type added',
            'renamed' => 'Document type renamed',
            'moved' => 'Position changed',
            'deactivated' => 'Document type deactivated',
            'activated' => 'Document type activated',
            'required' => 'Document type made required',
            'optional' => 'Document type made optional',
        ],
        'reviewed' => 'Lists marked reviewed',
    ],
];
