<?php

declare(strict_types=1);

// The names of Feedback's permissions (FeedbackPermissions): what every customer may do, the jobs the
// role editor shows under Support and Customers, and the admin-only switch.
return [
    'review' => [
        'write' => 'Review products delivered to me',
        'moderate' => 'Moderate the store\'s reviews',
    ],
    'question' => [
        'ask' => 'Ask questions about products',
        'answer' => 'Answer the store\'s product questions',
    ],
    'wishlist' => [
        'manage' => 'Keep my wishlist',
        'view' => 'View customers\' wishlists and favourited products',
    ],
    'settings' => [
        'update' => 'Switch ratings in the store\'s product lists',
    ],
];
