<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A calling code that is the start of another listed code, or starts with one - 96 beside 966, 2 beside
 * 20 - would make a number's country unclear: refused (owner, 2026-10-30; access.md amendment 90).
 */
final class CallingCodeOverlaps extends OpsError
{
    public function __construct(public readonly string $callingCode, public readonly string $otherCode, public readonly string $countryCode)
    {
        parent::__construct("The calling code {$callingCode} overlaps {$otherCode}, the code of \"{$countryCode}\".");
    }

    public function type(): string
    {
        return 'ops.calling_code_overlaps';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['calling_code' => $this->callingCode, 'other_code' => $this->otherCode, 'country' => $this->countryCode];
    }
}
