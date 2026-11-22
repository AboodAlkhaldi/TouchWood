<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Turning the base store off (platform.md §1.1, §7.3; owner, 2026-10-02). The base store is always
 * on; every other store may be switched either way.
 */
final class BaseStoreAlwaysActive extends PlatformError
{
    public function __construct(public readonly string $storeCode)
    {
        parent::__construct("The store \"{$storeCode}\" is the base store, which is always on.");
    }

    public function type(): string
    {
        return 'platform.base_store_always_active';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['code' => $this->storeCode];
    }
}
