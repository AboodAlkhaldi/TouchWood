<?php

declare(strict_types=1);

// Feedback's parts of the admin home (dashboard.md §2 F, J).
return [
    'rating' => [
        'title' => 'Average Rating',
        'reviews' => '{0} No reviews yet|{1} Out of 5, from :count review|[2,*] Out of 5, from :count reviews',
    ],
    'questions' => [
        'title' => 'Questions Waiting',
        'waiting' => 'Questions',
        'stores' => 'By Store',
        'count' => '{1} :count question|[2,*] :count questions',
        'open' => 'View Questions',
    ],
];
