<?php

declare(strict_types=1);

// The media library (frontend.md 3.5, E5).
return [
    'title' => 'Media Library',
    'subtitle' => 'Every file in the system, newest first.',

    'table' => 'Table',
    'grid' => 'Grid',
    'file' => 'File',
    'type' => 'Type',
    'size' => 'Size',
    'used_in' => 'Used In',
    'uploaded' => 'Uploaded',
    'not_used' => 'Not used',
    'dimensions' => ':width by :height',

    'variants_pending' => 'Being Processed',
    'variants_ready' => 'Ready',
    'variants_failed' => 'Processing Failed',
    'retry' => 'Retry Processing',
    'retrying' => 'Processing restarted',

    'upload' => 'Upload File',
    'uploaded_ok' => 'File uploaded',
    'visibility' => 'Who may see it',
    'visibility_public' => 'Anyone with the Link',
    'visibility_private' => 'Only the Panel',
    'no_file' => 'Couldn\'t upload the file. It may be larger than this server accepts.',

    'alt' => 'Description',
    'alt_hint' => 'Read aloud to somebody who cannot see the image.',
    'alt_ar' => 'Description in Arabic',
    'alt_en' => 'Description in English',
    'describe' => 'Edit Description',
    'described' => 'Description saved',

    'delete' => 'Delete File',
    'deleted' => 'File deleted',
    'delete_blocked' => 'This file is kept because of where it is used, and cannot be deleted.',
    'delete_confirm' => 'This file is used in :count places, and those uses will lose it.',
    'delete_confirm_unused' => 'Nothing uses this file.',

    'save' => 'Save Description',
    'cancel' => 'Cancel',
    'more' => 'Show More',
    'none' => 'No file yet.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Files Yet',
    'delete_title' => 'Delete File',
    'upload_needs_file' => 'Choose a file to upload first.',
];
