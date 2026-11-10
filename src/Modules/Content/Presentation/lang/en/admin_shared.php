<?php

declare(strict_types=1);

// What every Content screen says the same way (content.md §4.2, §1.3, §4.1's badges). Wording approved for now; the owner
// checks the whole site's wording later (reminder 16).
return [
    'state' => [
        'DRAFT' => 'Draft',
        'SCHEDULED' => 'Scheduled',
        'LIVE' => 'Live',
        'ENDED' => 'Ended',
        'PUBLISHED' => 'Published',
        'CANCELLED' => 'Cancelled',
    ],
    'store' => 'Store',
    'arabic' => 'Arabic',
    'english' => 'English',
    'none' => '—',
    'actions' => 'Actions',
    'starts' => 'Start',
    'ends' => 'End',
    'link' => 'Link',
    'link_helper' => 'A page of the shop without its store and language, such as /categories/chairs, or a full https:// address.',
    'characters' => ':count of :max characters',
    'translate' => [
        'button' => 'Translate',
        'all' => 'Translate All',
        'all_helper' => 'Fills every empty English box from its Arabic.',
        'marked' => 'Translated, read it before publishing',
        'arabic_first' => 'Type the Arabic first.',
        'not_set' => 'Translation isn\'t set up on this server.',
        'nothing_empty' => 'Every English box has its text.',
        'replace_title' => 'Replace the English text?',
        'replace_body' => 'The English typed in this box is replaced by the translation.',
        'replace' => 'Replace Text',
        'keep' => 'Keep Text',
        'failed' => 'Failed to translate. Type the English instead.',
        'lost_marks' => ':count paragraphs lost their bold or links in translation. Add them again.',
        'read_title' => 'Read the translated English',
        'read_body' => 'Some English was filled by Translate. Shoppers read it once this is saved.',
        'read_tick' => 'I read the translated English.',
    ],
    'image' => [
        'choose' => 'Choose Image',
        'replace' => 'Replace Image',
        'remove' => 'Remove',
        'uploading' => 'Uploading :percent%',
        'size' => 'Upload :width x :height.',
        'size_png' => 'Upload :width x :height, PNG.',
        'small' => 'This image is :width x :height, under the size it is shown at: it may look soft.',
        'pending' => 'Being prepared',
        'failed' => 'Upload this image again.',
        'upload_failed' => 'Failed to upload the image. Try again.',
        'none' => 'No image',
    ],
    'order' => [
        'top' => 'Move to Top',
        'bottom' => 'Move to Bottom',
    ],
    'dialog' => [
        'cancel' => 'Cancel',
    ],
    'leave' => 'Leave without saving? Your changes are lost.',
    'stale' => 'Someone saved this meanwhile. Open the page again to see their changes.',
];
