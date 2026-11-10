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

    /** An Arabic address with something other than Arabic letters and digits joined by single hyphens. */
    public const string SLUG_AR = 'slug_ar';

    /** An English address with something other than Latin letters and digits joined by single hyphens. */
    public const string SLUG_EN = 'slug_en';

    /** A title with nothing an address can be made from: the address is typed instead. */
    public const string SLUG_FROM_TITLE = 'slug_from_title';

    /** A file that is not a public JPEG, PNG or WebP image of the library (§1.1 rule 10). */
    public const string IMAGE_FILE = 'image_file';

    /** A text naming an image it does not hold (§1.2: "a reference that resolves to nothing is refused"). */
    public const string IMAGE_REF = 'image_ref';

    /** Fewer of something than it needs (`:min`). */
    public const string TOO_FEW = 'too_few';

    /** More of something than it may hold (`:max`). */
    public const string TOO_MANY = 'too_many';

    /** A value that is none of its box's choices. */
    public const string CHOICE = 'choice';

    /** A number outside its range (`:min`, `:max`). */
    public const string RANGE = 'range';

    /** Not a date and a time. */
    public const string DATE = 'date';

    /** An end that is not after the start. */
    public const string DATES = 'dates';

    /** An end already past, where something is being scheduled. */
    public const string PAST = 'past';

    /** A product that is not a ready one of the catalog. */
    public const string PRODUCT = 'product';

    /** A category that does not exist. */
    public const string CATEGORY = 'category';

    /** A brand that does not exist. */
    public const string BRAND = 'brand';

    /** A theme that does not exist, or has ended (§1.7 #7). */
    public const string THEME = 'theme';

    /** A campaign run that does not exist in this store, or is over (a smart bar's, §1.9). */
    public const string RUN = 'run';

    /** An email address written wrong. */
    public const string EMAIL = 'email';

    /** A phone number written wrong. */
    public const string PHONE = 'phone';

    /** An order that names other than every item it orders, each once. */
    public const string ORDER = 'order';

    /** A thing that is on the list already (a section type, a product, a file). */
    public const string DUPLICATE = 'duplicate';

    /** A step its item's state does not allow: End Now on what is not live, Back to Draft on what is not scheduled. */
    public const string STATE = 'state';

    /** A title pattern without `:title`, where a page's own title goes (§1.14). */
    public const string TITLE_PATTERN = 'title_pattern';

    /** A robots line that is not a shop path: `/` and then no space (§1.14, W10). */
    public const string ROBOTS_PATH = 'robots_path';

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
