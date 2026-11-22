<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class StoreNotFound extends PlatformError
{
    public function __construct(public readonly string $storeCode)
    {
        parent::__construct("No store has the code \"{$storeCode}\".");
    }

    public function type(): string
    {
        return 'platform.store_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['code' => $this->storeCode];
    }
}
