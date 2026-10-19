<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A store that is off, read or acted on by someone who may not switch stores (sync.md §7; pricing.md
 * §4.3.1 Q1, shared by every stage 5 screen): an off store is offered only to whoever may switch stores,
 * and the reads and actions keep refusing everyone else - as Pricing's and Inventory's do. The system's
 * own reads of an off store go on (§1.1 rule 8).
 */
final class StoreOff extends SyncError
{
    public function __construct()
    {
        parent::__construct('This store is off: only whoever may switch stores works in its Sync screens.');
    }

    public function type(): string
    {
        return 'sync.store_off';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
