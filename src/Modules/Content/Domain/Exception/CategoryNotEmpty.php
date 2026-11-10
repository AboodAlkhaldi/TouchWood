<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a blog category that holds posts (content.md §1.12, §7).
 */
final class CategoryNotEmpty extends ContentError
{
    public function __construct()
    {
        parent::__construct('The category holds posts.');
    }

    public function type(): string
    {
        return 'content.category_not_empty';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
