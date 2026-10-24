<?php

declare(strict_types=1);

// Feedback in the shop: the product page's parts, its dialogs, and the account's pages (feedback.md §4.1 F5–F8).
return [
    'rating' => [
        'stars' => ':rating out of 5 stars',
        'count' => ['one' => '(:count review)', 'other' => '(:count reviews)'],
        'to_reviews' => 'Go to reviews',
    ],
    'reviews' => [
        'title' => 'Reviews',
        'none' => 'No reviews yet.',
        'none_with' => 'No :star-star reviews.',
        'breakdown' => 'Reviews by stars',
        'bar' => ':star stars: :count',
        'only' => 'Show :star-star reviews only',
        'showing' => 'Showing :star-star reviews only.',
        'clear' => 'Show all reviews',
        'write' => 'Write a Review…',
        'written' => 'You reviewed this product.',
        'more' => 'Show More',
    ],
    'mark' => [
        'VERIFIED_PURCHASE' => 'Verified purchase',
        'RETURNED' => 'Returned',
    ],
    'questions' => [
        'title' => 'Questions',
        'none' => 'No answered questions yet.',
        'ask' => 'Ask a Question…',
        'sign_in' => 'Sign in to ask',
        'answer_by' => ':store team',
        'answered' => 'Answered',
        'more' => 'Show More',
    ],
    'author' => ':first :initial.',
    'deleted_customer' => 'Deleted customer',
    'save' => [
        'save' => 'Save',
        'saved' => 'Saved',
        'add' => 'Save :name',
        'remove' => 'Remove :name from saved',
    ],
    'write' => [
        'title' => 'Write a Review',
        'description' => 'Your review shows once the store approves it.',
        'stars' => 'Your rating',
        'star' => ['one' => ':count star', 'other' => ':count stars'],
        'body' => 'Your review',
        'submit' => 'Submit Review',
    ],
    'ask' => [
        'title' => 'Ask a Question',
        'description' => 'The answer shows here and in your account.',
        'body' => 'Your question',
        'submit' => 'Submit Question',
    ],
    'date' => [
        'week' => 'a week ago',
    ],
    'my' => [
        'reviews' => [
            'title' => 'My Reviews',
            'subtitle' => 'The reviews you wrote, in every store',
            'empty' => 'You haven\'t written a review yet.',
        ],
        'questions' => [
            'title' => 'My Questions',
            'subtitle' => 'The questions you asked, in every store',
            'empty' => 'You haven\'t asked a question yet.',
        ],
        'wishlist' => [
            'title' => 'My Wishlist',
            'subtitle' => 'The products you saved, by store',
            'empty' => 'Nothing saved yet.',
            'remove' => 'Remove',
            'store_off' => 'This store is closed for now.',
        ],
        'in_store' => 'In :store',
        'not_available' => 'Not available now',
        'more' => 'Show More',
    ],
    'review_status' => [
        'PENDING' => 'Waiting for approval',
        'APPROVED' => 'Published',
        'REJECTED' => 'Not published',
        'UNPUBLISHED' => 'Unpublished',
    ],
    'question_status' => [
        'PENDING' => 'Waiting for an answer',
        'ANSWERED' => 'Answered',
        'REJECTED' => 'Not published',
        'UNPUBLISHED' => 'Unpublished',
    ],
    'reason' => 'The store\'s reason: :reason',
    'toast' => [
        'reviewed' => 'Thanks — your review shows once the store approves it.',
        'asked' => 'Thanks — the answer shows here and in your account.',
        'saved' => 'Saved.',
        'removed' => 'Removed from saved.',
        'sign_in_to_save' => 'Sign in, and the product is saved.',
    ],
];
