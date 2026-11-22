<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class StoreCodeTaken extends PlatformError
{
    public function __construct(public readonly string $storeCode)
    {
        parent::__construct("Another store already uses the code \"{$storeCode}\".");
    }

    public function type(): string
    {
        return 'platform.store_code_taken';
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
