<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Another listed country already has this calling code: a number's country is read from its code, so
 * one code belongs to one country (access.md amendment 87).
 */
final class CallingCodeTaken extends OpsError
{
    public function __construct(public readonly string $callingCode, public readonly string $countryCode)
    {
        parent::__construct("The calling code {$callingCode} is already the code of \"{$countryCode}\".");
    }

    public function type(): string
    {
        return 'ops.calling_code_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['calling_code' => $this->callingCode, 'country' => $this->countryCode];
    }
}
