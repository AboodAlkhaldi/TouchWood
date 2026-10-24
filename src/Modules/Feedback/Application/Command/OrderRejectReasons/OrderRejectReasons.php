<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\OrderRejectReasons;

/** The ready reasons in a new order (feedback.md §2.4 B4): every reason, once, as the screen arranged them. */
final readonly class OrderRejectReasons
{
    /**
     * @param  list<string>  $reasonIds
     */
    public function __construct(
        public array $reasonIds,
    ) {}
}
