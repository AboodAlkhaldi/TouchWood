<?php

declare(strict_types=1);

/*
| Wording approved for now (owner, 2026-10-31; full review at the end) (ops.md §1.1). Every message kind Ops sends (MessageKind), in the
| person's language: the bell's title and line, and the email's subject and lines. Fixed here - there
| is no template editor (owner, round 1 #22). Numbers are written in Latin digits (owner, 2026-10-06).
|
| Placeholders: :name (the person's first name), :store (the store's name), :order (the order number,
| SA-0001), :amount (an amount with its currency), :reason (staff's words), and those named per kind.
| A line under `optional` is added only when its value exists ("No reason, no line").
|
| C18 (security: codes and links) has no bell entry; its words, Access's approved ones, are in
| security.php (ops.md §1.5 C18).
*/
return [
    // Messages to customers (§1.5).
    'order_taken' => [
        'title' => 'Order :order received',
        'line' => 'We received your order and will review it soon.',
        'subject' => 'We received your order :order',
        'lines' => [
            'Hello :name,',
            'Thank you for your order from :store. Its number is :order.',
            'We will let you know as soon as it is approved.',
        ],
        'optional' => [
            'paid' => 'Your payment of :amount is received.',
            'address' => "Delivery to:\n:recipient\n:address",
        ],
    ],
    'order_approved' => [
        'title' => 'Order :order approved',
        'line' => 'We are preparing your order.',
        'subject' => 'Your order :order is approved',
        'lines' => [
            'Hello :name,',
            'Your order :order is approved and we are preparing it.',
        ],
    ],
    'order_rejected' => [
        'title' => 'Order :order not accepted',
        'line' => 'We could not accept your order.',
        'subject' => 'Your order :order was not accepted',
        'lines' => [
            'Hello :name,',
            'We are sorry: we could not accept your order :order.',
        ],
        'optional' => [
            'reason' => 'Reason: :reason',
        ],
    ],
    'order_shipped' => [
        'title' => 'Order :order shipped',
        'line' => 'Your order is on its way with :carrier.',
        'subject' => 'Your order :order is on its way',
        'lines' => [
            'Hello :name,',
            'Your order :order has been shipped with :carrier.',
        ],
        // When the order names no carrier.
        'without_carrier' => [
            'line' => 'Your order is on its way.',
            'lines' => [
                'Hello :name,',
                'Your order :order has been shipped.',
            ],
        ],
        'optional' => [
            'tracking_number' => 'Tracking number: :tracking_number',
            'track' => 'Track your order',
        ],
    ],
    'order_delivered' => [
        'title' => 'Order :order delivered',
        'line' => 'You can ask for a return within :days.',
        'subject' => 'Your order :order is delivered',
        'lines' => [
            'Hello :name,',
            'Your order :order has been delivered. We hope you enjoy it.',
            'You can ask for a return within :days of delivery.',
        ],
    ],
    'order_cancelled' => [
        'title' => 'Order :order cancelled',
        'line' => 'Your order is cancelled.',
        'subject' => 'Your order :order is cancelled',
        'lines' => [
            'Hello :name,',
            'Your order :order is cancelled.',
        ],
    ],
    'order_changed' => [
        'title' => 'Order :order changed',
        'line' => 'Your order was updated. The new total is :amount.',
        'subject' => 'Your order :order was changed',
        'lines' => [
            'Hello :name,',
            'We changed your order :order. Its new items and total are below.',
            'New total: :amount',
        ],
    ],
    'order_came_back' => [
        'title' => 'Order :order came back',
        'line' => 'Your order could not be delivered and came back to us.',
        'subject' => 'Your order :order came back to us',
        'lines' => [
            'Hello :name,',
            'Your order :order could not be delivered and came back to us.',
            'Contact us if you still want it.',
        ],
    ],
    'return_accepted' => [
        'title' => 'Return for order :order accepted',
        'line' => 'Your return request is accepted.',
        'subject' => 'Your return for order :order is accepted',
        'lines' => [
            'Hello :name,',
            'Your return request for order :order is accepted. We will tell you the next steps.',
        ],
    ],
    'return_refused' => [
        'title' => 'Return for order :order not accepted',
        'line' => 'Your return request was not accepted.',
        'subject' => 'Your return for order :order was not accepted',
        'lines' => [
            'Hello :name,',
            'We could not accept your return request for order :order.',
        ],
        'optional' => [
            'reason' => 'Reason: :reason',
        ],
    ],
    'return_received' => [
        'title' => 'Return for order :order received',
        'line' => 'We received the items you sent back.',
        'subject' => 'We received your return for order :order',
        'lines' => [
            'Hello :name,',
            'We received the items you sent back from order :order. We are checking them now.',
        ],
    ],
    'return_completed' => [
        'title' => 'Return for order :order completed',
        'line' => 'A refund of :amount is owed to you.',
        'subject' => 'Your return for order :order is completed',
        'lines' => [
            'Hello :name,',
            'Your return for order :order is completed. A refund of :amount is owed to you.',
            'We will tell you when the money is sent.',
        ],
    ],
    'refund_sent' => [
        'title' => 'Refund sent for order :order',
        'line' => 'We sent you :amount by :way.',
        'subject' => 'Your refund for order :order is sent',
        'lines' => [
            'Hello :name,',
            'We sent you a refund of :amount for order :order, by :way.',
        ],
        'ways' => [
            'BANK_TRANSFER' => 'bank transfer',
            'ONLINE' => 'online payment',
            'OTHER' => 'another method',
        ],
    ],
    'payment_received' => [
        'title' => 'Payment received for order :order',
        'line' => 'We received :amount.',
        'subject' => 'We received your payment for order :order',
        'lines' => [
            'Hello :name,',
            'We received your payment of :amount for order :order. Thank you.',
        ],
    ],
    'pay_remaining' => [
        'title' => 'Pay the rest of order :order',
        'line' => ':amount is left to pay. The link works for 60 minutes.',
        'subject' => 'Pay the remaining amount of order :order',
        'lines' => [
            'Hello :name,',
            ':amount is left to pay for order :order.',
            'The payment link below works for 60 minutes.',
        ],
        'action' => 'Pay now',
    ],
    'points_earned' => [
        'title' => 'You earned :points points',
        'line' => 'Points for order :order are in your account.',
        'subject' => 'You earned :points points',
        'lines' => [
            'Hello :name,',
            'You earned :points points for order :order. You can use them on your next order.',
        ],
    ],
    'points_changed_by_hand' => [
        'title' => 'Your points changed',
        'line' => 'Your points balance changed by :points.',
        'subject' => 'Your points balance changed',
        'lines' => [
            'Hello :name,',
            'Our team changed your points balance by :points.',
        ],
        'optional' => [
            'reason' => 'Reason: :reason',
        ],
    ],
    'points_expiring' => [
        'title' => ':points points expire on :date',
        'line' => 'Use them before they expire.',
        'subject' => 'Your points expire on :date',
        'lines' => [
            'Hello :name,',
            ':points of your points expire on :date. Use them on an order before then.',
        ],
    ],
    'coupon_assigned' => [
        'title' => 'A coupon for you',
        'line' => 'Use the code :code at checkout.',
        'subject' => 'A coupon for you from :store',
        'lines' => [
            'Hello :name,',
            'You have a new coupon. Use the code :code at checkout.',
        ],
    ],
    'question_answered' => [
        'title' => 'Your question is answered',
        'line' => 'We answered your question about :product.',
        'subject' => 'Your question about :product is answered',
        'lines' => [
            'Hello :name,',
            'We answered your question about :product.',
        ],
    ],
    'review_not_published' => [
        'title' => 'Your review is not published',
        'line' => 'Your review of :product is not published.',
        'subject' => 'Your review of :product is not published',
        'lines' => [
            'Hello :name,',
            'Your review of :product is not published.',
            'Reason: :reason',
        ],
    ],
    'question_not_published' => [
        'title' => 'Your question is not published',
        'line' => 'Your question about :product is not published.',
        'subject' => 'Your question about :product is not published',
        'lines' => [
            'Hello :name,',
            'Your question about :product is not published.',
            'Reason: :reason',
        ],
    ],

    // Alerts to staff (§1.6). An alert links to its page and never copies details that may change.
    'new_order' => [
        'title' => 'New order :order',
        'line' => 'A new order was placed in :store.',
        'subject' => 'New order :order in :store',
        'lines' => [
            'Hello :name,',
            'A new order, :order, was placed in :store.',
        ],
    ],
    'company_application' => [
        'title' => 'New company application',
        'line' => 'A company applied in :store.',
        'subject' => 'New company application in :store',
        'lines' => [
            'Hello :name,',
            'A company sent an application in :store. It is waiting for review.',
        ],
    ],
    'low_stock' => [
        'title' => 'Low stock in :store',
        'line' => ':low sizes are low and :out ran out.',
        'subject' => 'Low stock in :store',
        'lines' => [
            'Hello :name,',
            'At the last count in :store, :low sizes became low and :out ran out.',
        ],
    ],
    'sync_problems' => [
        'title' => 'Sync problems in :store',
        'line' => 'The last read left problems to check.',
        'subject' => 'Sync problems in :store',
        'lines' => [
            'Hello :name,',
            'The last read from the store system of :store left problems to check in the Sync report.',
        ],
    ],
    'gateway_key_expiring' => [
        'title' => 'The :mode payment key expires in :days',
        'line' => 'Renew the :mode payment key of :store before :date.',
        'subject' => 'The :mode payment key of :store expires on :date',
        // Which key, never that payments stop: a key of the mode not in use stops nothing (2026-11-01).
        'lines' => [
            'Hello :name,',
            'The :mode payment gateway key of :store expires on :date, in :days.',
            'Renew it before then.',
        ],
        'sms' => 'TouchWood: the :mode payment key of :store expires on :date. Renew it today.',
    ],
    'gateway_key_rejected' => [
        'title' => 'Payment key refused',
        'line' => 'The payment gateway refused the :mode key of :store.',
        'subject' => 'The :mode payment key of :store was refused',
        // Which key, never that payments stopped: a refused key of the mode not in use stops nothing (2026-11-01).
        'lines' => [
            'Hello :name,',
            'The payment gateway refused the :mode key of :store. Replace it with a new key.',
        ],
        'sms' => 'TouchWood: the payment gateway refused the :mode key of :store. Replace it now.',
    ],
    'transfer_overdue' => [
        'title' => 'Transfer to check for order :order',
        'line' => 'A bank transfer has waited 72 hours for a check.',
        'subject' => 'A bank transfer waits for a check: order :order',
        'lines' => [
            'Hello :name,',
            'The bank transfer receipt of order :order in :store has waited 72 hours for a check.',
        ],
    ],
    'payment_not_accepted' => [
        'title' => 'Payment not accepted in :store',
        'line' => 'A payment was not accepted. A refund is owed.',
        'subject' => 'A payment was not accepted in :store',
        'lines' => [
            'Hello :name,',
            'A payment in :store was not accepted, and a refund is owed to the customer.',
        ],
    ],
    'campaign_expiring' => [
        'title' => 'Campaign ending soon',
        'line' => 'A campaign in :store ends on :date.',
        'subject' => 'A campaign in :store ends on :date',
        'lines' => [
            'Hello :name,',
            'A campaign in :store ends on :date.',
        ],
    ],
    'return_requested' => [
        'title' => 'Return asked for order :order',
        'line' => 'A customer asked for a return in :store.',
        'subject' => 'Return asked for order :order',
        'lines' => [
            'Hello :name,',
            'A customer asked for a return of order :order in :store. It waits under Returns.',
        ],
    ],

    // Steps 5 and 6: the button that opens a message's page, and the order's items and totals in its
    // two emails (C1, C6b).
    'open' => 'View details',
    // Wording approved for now (owner, 2026-11-01; full review at the end).
    'key_modes' => [
        'TEST' => 'test',
        'LIVE' => 'live',
    ],
    'day_count' => '{0} 0 days|{1} 1 day|[2,*] :count days',
    'summary' => [
        'gift' => 'Free gift',
        'subtotal' => 'Subtotal',
        'coupon' => 'Coupon',
        'points' => 'Points',
        'shipping' => 'Shipping',
        'vat' => 'VAT',
        'total' => 'Total',
        'way_to_pay' => 'Payment',
        'ways' => [
            'ONLINE' => 'Paid online',
            'BANK_TRANSFER' => 'Bank transfer',
            'STAFF_CONTACT' => 'Our team arranges payment with you',
        ],
    ],

    // Step 7 (§1.7-§1.9), approved for now (owner, 2026-10-31; full review at the end): the frame around what staff write, the
    // unsubscribe line, and the newsletter's confirmation. :title and :line are the staff's own words.
    'offer_notice' => [
        'title' => ':title',
        'line' => ':line',
        'subject' => ':title',
        'lines' => [
            'Hello :name,',
            ':line',
        ],
        'action' => 'See the offer',
    ],
    'staff_message' => [
        'title' => ':title',
        'line' => ':line',
        'subject' => ':title',
        'lines' => [
            'Hello :name,',
            ':line',
        ],
    ],
    'newsletter_confirmation' => [
        'subject' => 'Confirm your subscription to :store news',
        'lines' => [
            'Hello,',
            'Confirm that you want to receive offers and news from :store by email.',
            'If you did not ask for this, ignore this email and nothing will be sent to you.',
        ],
        'action' => 'Confirm my subscription',
        // Once confirmed, the guest may leave at any time (§3).
        'leave' => [
            'text' => 'Changed your mind later? You can leave the list at any time.',
            'action' => 'Unsubscribe',
        ],
    ],
    // Every marketing email ends with this line and its link (§1.3).
    'unsubscribe' => [
        'text' => 'You get this email because you agreed to receive offers and news from :store.',
        'action' => 'Unsubscribe',
    ],
];
