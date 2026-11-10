<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown id, or one of a store the reader holds nothing in: the same answer (content.md §7).
 */
final class ContentNotFound extends ContentError
{
    public function __construct()
    {
        parent::__construct('There is no such item.');
    }

    public function type(): string
    {
        return 'content.content_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
