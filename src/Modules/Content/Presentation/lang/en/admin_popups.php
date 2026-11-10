<?php

declare(strict_types=1);

// W6 · Pop-Ups (content.md §4.4, §1.10). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'Pop-Ups',
    'subtitle' => 'One message that opens over the shop, once for each visitor.',
    'new' => 'New Pop-Up',
    'edit' => 'Edit Pop-Up',
    'empty' => 'No pop-ups yet.',
    'empty_body' => 'A pop-up opens a few seconds after a visitor arrives, once, never at checkout.',
    'columns' => [
        'name' => 'Name',
        'state' => 'State',
        'starts' => 'Start',
        'ends' => 'End',
    ],
    'no_end' => 'No end',
    'now' => 'Now',
    'fields' => [
        'name' => 'Name',
        'name_helper' => 'For staff only, 100 characters at most.',
        'title_ar' => 'Title (Arabic)',
        'title_en' => 'Title (English)',
        'title_rule' => '80 characters at most.',
        'text_ar' => 'Text (Arabic)',
        'text_en' => 'Text (English)',
        'text_rule' => '240 characters at most.',
        'button_ar' => 'Button (Arabic)',
        'button_en' => 'Button (English)',
        'button_rule' => 'Optional, 40 characters at most, with its link.',
        'delay' => 'Opens After (Seconds)',
        'delay_helper' => 'From 3 to 60 seconds after the page opens.',
        'image' => 'Image',
        'image_helper' => 'Optional. Shown on a computer, left out on a phone.',
    ],
    'preview' => [
        'title' => 'Preview',
        'phone' => 'Phone',
        'desktop' => 'Computer',
        'close' => 'Close',
    ],
    'save_draft' => 'Save Draft',
    'schedule' => 'Schedule Pop-Up',
    'save' => 'Save Changes',
    'saved' => 'Pop-up saved',
    'scheduled' => 'Pop-up scheduled',
    'menu' => [
        'edit' => 'Edit',
        'end' => 'End Now',
        'draft' => 'Back to Draft',
        'delete' => 'Delete Pop-Up',
    ],
    'end' => [
        'title' => 'End this pop-up now?',
        'body' => 'It stops opening at once, and stays listed as ended.',
        'confirm' => 'End Now',
    ],
    'delete' => [
        'title' => 'Delete this pop-up?',
        'body' => 'A draft is deleted for good. This can\'t be undone.',
        'confirm' => 'Delete Pop-Up',
    ],
    'ended' => 'Pop-up ended',
    'drafted' => 'Pop-up moved back to draft',
    'deleted' => 'Pop-up deleted',
    'ended_note' => 'This pop-up has ended. It stays as it was, for the record.',
];
