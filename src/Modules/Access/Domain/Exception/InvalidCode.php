<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A wrong or expired SMS code. After too many wrong tries the code is dead and a new one must be
 * requested (spec §1.3).
 */
final class InvalidCode extends AccessError
{
    public function __construct(public readonly bool $requestNewCode = false)
    {
        parent::__construct($requestNewCode ? 'This code can no longer be used; request a new one.' : 'The code is wrong or has expired.');
    }

    public function type(): string
    {
        return 'access.invalid_code';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
