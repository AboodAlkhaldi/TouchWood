<?php

declare(strict_types=1);

// Feedback's error messages, by error type (feedback.{key}), and the names of the fields they point at
// (feedback.md §7).
return [
    'not_a_verified_purchase' => [
        'title' => 'Not a Verified Purchase',
        'detail' => 'You can review a product once it has been delivered to you.',
    ],
    'already_reviewed' => [
        'title' => 'Already Reviewed',
        'detail' => 'You have already reviewed this product. A review can\'t be written twice.',
    ],
    'too_many_waiting_questions' => [
        'title' => 'Too Many Questions Waiting',
        'detail' => 'Five of your questions are waiting for an answer. Ask again once one is answered.',
    ],
    'wishlist_full' => [
        'title' => 'Wishlist Full',
        'detail' => 'Your wishlist already holds 500 products. Remove one to save another.',
    ],
    'invalid_moderation' => [
        'title' => 'Can\'t Do That Now',
        'detail' => 'This can\'t be done in its current state. Refresh the page and try again.',
    ],
    'review_not_found' => [
        'title' => 'Review Not Found',
        'detail' => 'No such review was found in this store.',
    ],
    'question_not_found' => [
        'title' => 'Question Not Found',
        'detail' => 'No such question was found in this store.',
    ],
    'invalid_feedback_attribute' => [
        'title' => 'Check the Details',
        'detail' => 'The :attribute isn\'t valid. Check it and try again.',
    ],

    'fields' => [
        'rating' => 'rating',
        'body' => 'text',
        'answer' => 'answer',
        'reason' => 'reason',
        'product' => 'product',
        'variant' => 'variant',
        'store' => 'store',
        'customer' => 'customer',
        'locale' => 'language',
    ],
];
