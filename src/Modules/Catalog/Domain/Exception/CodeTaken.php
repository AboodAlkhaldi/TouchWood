<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A code another product holds, or once held (catalog.md §1.2, §7, amendment 3(e)). The code is
 * kept as `$takenCode`: `$code` is PHP's own exception code.
 */
final class CodeTaken extends CatalogError
{
    public function __construct(public readonly string $takenCode)
    {
        parent::__construct("The code {$this->takenCode} belongs to another product.");
    }

    public function type(): string
    {
        return 'catalog.code_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['code' => $this->takenCode];
    }
}
