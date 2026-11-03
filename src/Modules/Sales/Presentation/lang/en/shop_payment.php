<?php

declare(strict_types=1);

// Sales in the shop: paying online at checkout (payments.md §4.2).

return [
    'test_label' => 'Test Mode',
    'test_note' => 'Test mode — no real money is taken.',
    'pay' => 'Pay · :total',
    'loading' => 'The payment form is loading.',
    'form_failed' => 'The payment form couldn\'t load. Try again, or choose Staff will contact you.',
    'form_refused' => 'Your payment details couldn\'t be sent. Check them and try again.',
    'try_again' => 'Try Again',
    'too_many' => 'Too many tries — wait a few minutes.',
    'changed' => 'Something in your order changed. Check the new totals, then pay.',
    'time_left' => 'Time left',
    'confirming' => 'Confirming your payment…',
    'ran_out' => 'Time ran out.',
    'still_confirming' => 'We are still confirming with your bank. If the payment goes through, your order appears in My Orders — please do not pay again.',
    'frame' => [
        'title' => 'Your Bank\'s Confirmation',
        'body' => 'Follow your bank\'s steps to confirm this payment.',
        'leave_title' => 'Leave the bank\'s page?',
        'leave_body' => 'Your payment may still go through until :time.',
        'stay' => 'Stay',
        'leave' => 'Leave',
    ],
    'in_the_way' => 'Your last payment can still go through until :time. You can change your cart meanwhile; Pay opens again after that.',
    'pay_later' => 'Your last payment can still go through. Pay opens again after that.',
    'other_window' => 'A payment is already in progress in another window.',
    'ended' => [
        'declined' => 'Your card was declined: :reason. No money was taken.',
        'declined_general' => 'Your bank declined this payment. No money was taken.',
        'reason' => [
            'MF001' => 'your bank\'s code wasn\'t confirmed',
            'MF004' => 'there aren\'t enough funds',
            'MF007' => 'the card has expired',
            'MF010' => 'the security code is wrong',
        ],
        'could_not_start' => 'The payment could not start — please try again.',
        'nothing_taken' => 'No money was taken.',
        'not_completed' => 'The payment was not completed. If your bank took the money, our team will contact you to return it.',
        'not_accepted' => 'We couldn\'t accept this payment. Our team will contact you to return the money.',
    ],
    'closed' => [
        'title' => 'This Store Is Closed',
        'paid' => 'Your order :number is recorded. This store is closed for now; our team will contact you.',
        'nothing_taken' => 'This store is closed for now. No money was taken.',
        'not_completed' => 'This store is closed for now. The payment was not completed; if your bank took the money, our team will contact you to return it.',
        'main' => 'Go to the Main Store',
    ],
];
