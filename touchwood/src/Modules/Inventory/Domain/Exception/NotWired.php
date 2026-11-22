<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The stock-dependent switch in a store with no provider (inventory.md §1.2, §3, §7): there every
 * product counts on its stock already.
 */
final class NotWired extends InventoryError
{
    public function __construct()
    {
        parent::__construct('Only a store wired to a provider has stock-dependent sizes.');
    }

    public function type(): string
    {
        return 'inventory.not_wired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
