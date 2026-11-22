<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A redirect sheet of the wrong type, without headings, over 5,000 lines or 2 MB (content.md §1.15, §7).
 */
final class SheetRefused extends ContentError
{
    /** Neither an xlsx nor a csv file, or one that can't be read as one. */
    public const string TYPE = 'type';

    /** Over 2 MB. */
    public const string TOO_LARGE = 'too_large';

    /** Over 5,000 lines under the headings. */
    public const string TOO_MANY_LINES = 'too_many_lines';

    /** A first row that is not the two headings, Old Address and New Address. */
    public const string HEADINGS = 'headings';

    /** No line under the headings. */
    public const string EMPTY = 'empty';

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
