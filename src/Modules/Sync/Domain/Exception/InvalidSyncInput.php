<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value that is not one (sync.md §7): a store that does not exist, an address that is not HTTPS, an
 * empty key or secret, a provider we do not have. Named by its field, as Catalog's invalid attribute is.
 */
final class InvalidSyncInput extends SyncError
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("Invalid {$attribute}.");
    }

    public function type(): string
    {
        return 'sync.invalid_sync_input';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
