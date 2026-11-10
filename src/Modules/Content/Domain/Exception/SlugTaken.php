<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The address is another item's, current or held by its history (content.md §1.1 rule 15, §7).
 */
final class SlugTaken extends ContentError
{
    /**
     * @param  string  $locale  the address's language
     * @param  string  $slug  the address asked for
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $slug,
    ) {
        parent::__construct("The address {$slug} is taken.");
    }

    public function type(): string
    {
        return 'content.slug_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['locale' => $this->locale, 'slug' => $this->slug];
    }
}
