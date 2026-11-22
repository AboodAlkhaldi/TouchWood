<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A correction with no note (inventory.md §1.5, §7): a correction says why.
 */
final class NoteRequired extends InventoryError
{
    public function __construct()
    {
        parent::__construct('A correction needs a note.');
    }

    public function type(): string
    {
        return 'inventory.note_required';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
