<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Two values of one attribute, or two word pairs, that match after trimming, ignoring letter case (catalog.md §1.7, §1.11): "Black" and "black" are one value.
 */
final class NameTaken extends CatalogError
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("That {$this->attribute} is already in the list.");
    }

    public function type(): string
    {
        return 'catalog.name_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
