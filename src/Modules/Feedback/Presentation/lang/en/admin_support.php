<?php

declare(strict_types=1);

// Support's Reviews and Product Questions, and Customers' Favourited Products (feedback.md §4.1 F1–F3).
return [
    'reviews' => [
        'title' => 'Reviews',
        'subtitle' => 'What customers wrote, waiting for your decision',
        'tabs_label' => 'Reviews by status',
        'empty' => 'No reviews here.',
    ],
    'questions' => [
        'title' => 'Product Questions',
        'subtitle' => 'Questions customers asked about products',
        'tabs_label' => 'Questions by status',
        'empty' => 'No questions here.',
    ],
    'favourites' => [
        'title' => 'Favourited Products',
        'subtitle' => 'The products customers saved in this store, most saved first',
        'empty' => 'No product saved yet.',
        'saved_by' => 'Saved by',
        'product' => 'Product',
    ],
    'wishlist' => [
        'title' => 'Saved Products',
        'none' => 'Nothing saved in your stores.',
    ],
    'review_status' => [
        'PENDING' => 'Waiting',
        'APPROVED' => 'Published',
        'REJECTED' => 'Not Published',
        'UNPUBLISHED' => 'Unpublished',
    ],
    'question_status' => [
        'PENDING' => 'Waiting',
        'ANSWERED' => 'Answered',
        'REJECTED' => 'Rejected',
        'UNPUBLISHED' => 'Unpublished',
    ],
    'mark' => [
        'VERIFIED_PURCHASE' => 'Verified purchase',
        'RETURNED' => 'Returned',
    ],
    'item' => [
        'archived' => 'Archived',
        'not_sold' => 'Not sold here',
        'removed' => 'No longer in the catalog',
        'deleted_customer' => 'Deleted customer',
        'author' => ':first :initial.',
        'stars' => ':rating out of 5 stars',
        'decided' => 'Decided by :name',
        'answered_by' => 'Answered by :name',
        'reason' => 'Reason: :reason',
    ],
    'actions' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'unpublish' => 'Unpublish',
        'answer' => 'Answer',
        'correct' => 'Correct Answer',
    ],
    'dialog' => [
        'reject_review' => 'Reject Review',
        'unpublish_review' => 'Unpublish Review',
        'reject_question' => 'Reject Question',
        'unpublish_question' => 'Unpublish Question',
        'reason' => 'Reason',
        'reason_hint' => 'Optional. With a reason, the customer sees it in their account; without one, they see only "Not published".',
        'answer_title' => 'Answer Question',
        'correct_title' => 'Correct Answer',
        'answer' => 'Answer',
        'answer_hint' => 'Signed ":store team" in every store that sells the product.',
        'save_answer' => 'Publish Answer',
    ],
    'toast' => [
        'approved' => 'Review approved.',
        'rejected' => 'Review rejected.',
        'unpublished' => 'Review unpublished.',
        'answered' => 'Answer published.',
        'corrected' => 'Answer corrected.',
        'question_rejected' => 'Question rejected.',
        'question_unpublished' => 'Question unpublished.',
    ],
];
