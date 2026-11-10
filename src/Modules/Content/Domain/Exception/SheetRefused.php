<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A redirect sheet of the wrong type, without headings, over 5,000 lines or 2 MB (content.md §1.15, §7).
 */
final class SheetRefused extends ContentError
{
    /**
     * @param  string  $rule  the rule, as its words are keyed
     */
    public function __construct(
        public readonly string $rule,
    ) {
        parent::__construct("The sheet is refused ({$rule}).");
    }

    public function type(): string
    {
        return 'content.sheet_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['rule' => $this->rule];
    }
}
