<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Any use case in a store that is off, by someone who may not switch stores (content.md §1.1 rule 7, §7).
 */
final class StoreOff extends ContentError
{
    public function __construct()
    {
        parent::__construct('This store is off: only whoever may switch stores works in its Content screens.');
    }

    public function type(): string
    {
        return 'content.store_off';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
