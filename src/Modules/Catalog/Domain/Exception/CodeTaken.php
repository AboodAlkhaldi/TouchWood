<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A code another variant carries now (catalog.md §1.2, §7, amendment 16(a)), or another product holds
 * or once held (amendment 3(e)). The code is kept as `$takenCode`: `$code` is PHP's own exception code.
 */
final class CodeTaken extends CatalogError
{
    public function __construct(public readonly string $takenCode)
    {
        parent::__construct("The code {$this->takenCode} is taken: another variant carries it, or another product holds it.");
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
