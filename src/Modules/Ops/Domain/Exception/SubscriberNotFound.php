<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown newsletter subscriber, one of a store the reader may not manage, or a customer - whom
 * staff never remove: their consent is their own (ops.md §1.3, §3). The same answer for all.
 */
final class SubscriberNotFound extends OpsError
{
    public function __construct()
    {
        parent::__construct('No such subscriber.');
    }

    public function type(): string
    {
        return 'ops.subscriber_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
