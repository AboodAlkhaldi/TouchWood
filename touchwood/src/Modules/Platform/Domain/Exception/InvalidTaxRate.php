<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class InvalidTaxRate extends PlatformError
{
    public function __construct(public readonly int $basisPoints)
    {
        parent::__construct("Invalid tax rate {$basisPoints}: expected basis points between 0 and 10000.");
    }

    public function type(): string
    {
        return 'platform.invalid_tax_rate';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['basis_points' => $this->basisPoints];
    }
}
