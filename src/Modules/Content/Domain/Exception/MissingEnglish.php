<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Publish or Schedule with an English side empty, or English blanked on something published, scheduled or
 * live (content.md §1.1 rule 2, §7), naming which box.
 */
final class MissingEnglish extends ContentError
{
    /**
     * @param  string  $attribute  the box whose English is missing
     */
    public function __construct(
        public readonly string $attribute,
    ) {
        parent::__construct("The English of {$attribute} is missing.");
    }

    public function type(): string
    {
        return 'content.missing_english';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
