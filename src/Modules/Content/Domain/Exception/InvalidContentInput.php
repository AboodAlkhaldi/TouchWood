<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A box against its rule (content.md §7), naming the field and the rule: a length, a link outside §1.1
 * rule 16, an image or a table written wrong - with the line of the typed text it is on, when it has one
 * ("Line 12: the table has 3 columns here and 4 above.", §1.2).
 *
 * `$block` is the place of the block in a stored document, for the typed-text reader to turn into the line
 * a person sees; it is not shown. `$typedLine` is that line (named so: an exception's own `$line` is PHP's).
 */
final class InvalidContentInput extends ContentError
{
    /** A text past its number of characters (`:max`). */
    public const string TOO_LONG = 'too_long';

    /** A box left empty, or holding only spaces. */
    public const string REQUIRED = 'required';

    /** A text holding a line break or a control character where one line is kept. */
    public const string ONE_LINE = 'one_line';

    /** A formatted text whose stored shape is not one (a block of no kind, a run with keys it cannot have). */
    public const string FORMAT = 'format';

    /** A link that is neither a page of the shop nor a full https:// address (§1.1 rule 16). */
    public const string LINK = 'link';

    /** A heading of a level other than 2 or 3. */
    public const string HEADING_LEVEL = 'heading_level';

    /** An image mark not on a line of its own, or with no description. */
    public const string IMAGE = 'image';

    /** More images than a document may hold (`:max`). */
    public const string TOO_MANY_IMAGES = 'too_many_images';

    /** A table of fewer than 2 or more than 8 columns. */
    public const string TABLE_COLUMNS = 'table_columns';

    /** A table row with another number of columns than the heading (`:found`, `:expected`). */
    public const string TABLE_ROW_COLUMNS = 'table_row_columns';

    /** A table of no row, or more than 100. */
    public const string TABLE_ROWS = 'table_rows';

    /** More blocks than a document may hold (`:max`). */
    public const string TOO_MANY_BLOCKS = 'too_many_blocks';

    /** A list of more items than it may hold (`:max`). */
    public const string TOO_MANY_ITEMS = 'too_many_items';

    /** A kind of item that does not exist, where a request names one. */
    public const string KIND = 'kind';

    /** A store that does not exist, where a request names one. */
    public const string STORE = 'store';

    /** A language that is neither "ar" nor "en". */
    public const string LANGUAGE = 'language';

    /** A text past the bytes one request may carry, refused before it is read (`TranslateTextHandler::MAX_BYTES`). */
    public const string TOO_LARGE = 'too_large';

    /**
     * @param  array<string, string|int>  $values  the rule's own gaps (`max`, `found`, `expected`)
     */
    public function __construct(
        public readonly string $attribute,
        public readonly string $rule,
        public readonly ?int $typedLine = null,
        public readonly array $values = [],
        public readonly ?int $block = null,
    ) {
        parent::__construct("Invalid {$attribute} ({$rule})".($typedLine === null ? '' : " on line {$typedLine}").'.');
    }

    /** The same refusal, said on the line of the typed text the block came from. */
    public function onLine(int $line): self
    {
        return new self($this->attribute, $this->rule, $line, $this->values);
    }

    public function type(): string
    {
        return 'content.invalid_content_input';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        // A refusal on a line names the line's rule (`rules.<rule>_line`), the same words after "Line 12:".
        return [
            ...$this->values,
            'attribute' => $this->attribute,
            'rule' => $this->typedLine === null ? $this->rule : "{$this->rule}_line",
            ...($this->typedLine === null ? [] : ['line' => $this->typedLine]),
        ];
    }
}
