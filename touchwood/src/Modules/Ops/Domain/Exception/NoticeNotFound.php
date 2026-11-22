<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown notice, or one of a store the reader may not manage notices in: the same answer for both
 * (ops.md §3); someone who manages notices nowhere is refused first (Writers::storesHolding).
 */
final class NoticeNotFound extends OpsError
{
    public function __construct()
    {
        parent::__construct('No such notice.');
    }

    public function type(): string
    {
        return 'ops.notice_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
