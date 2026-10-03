<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A slug another product, category or brand holds or once held (catalog.md §1.1, §7): an old slug keeps answering with a redirect, so it is never given to another.
 */
final class SlugTaken extends CatalogError
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("The slug {$this->slug} is taken.");
    }

    public function type(): string
    {
        return 'catalog.slug_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['slug' => $this->slug];
    }
}
