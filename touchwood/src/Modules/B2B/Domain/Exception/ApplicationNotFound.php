<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The account has no open application for a draft's action to act on (b2b.md §3.1, §7): the
 * draft's actions name no application, so there is none to find until one is started.
 */
final class ApplicationNotFound extends B2BError
{
    public function __construct()
    {
        parent::__construct('There is no application to change: start one first.');
    }

    public function type(): string
    {
        return 'b2b.application_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
