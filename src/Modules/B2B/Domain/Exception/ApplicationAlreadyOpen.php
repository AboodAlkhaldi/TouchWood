<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Starting a draft while a sent application still waits for staff (b2b.md §3.1, §7): one open
 * application per account at a time (handoff §8.2). Starting while a draft is open returns that
 * draft instead, so this is only ever about a sent one.
 */
final class ApplicationAlreadyOpen extends B2BError
{
    public function __construct()
    {
        parent::__construct('An application is already waiting for a decision.');
    }

    public function type(): string
    {
        return 'b2b.application_already_open';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
