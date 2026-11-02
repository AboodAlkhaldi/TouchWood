<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\FollowCheckoutPayment;

/** The page's own answer (§9.5 #4): the frame's message, the return, the form's callback, or its clock. */
final readonly class FollowCheckoutPayment
{
    public function __construct(
        public string $tryId,
    ) {}
}
