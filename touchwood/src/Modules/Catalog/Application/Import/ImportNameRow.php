<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * A name a product file uses that the catalog does not have (catalog.md §1.12, page part 1), listed
 * once with the products using it, for the Super Admin to decide.
 */
final readonly class ImportNameRow
{
    public const string CATEGORY = 'CATEGORY';

    public const string BRAND = 'BRAND';

    public const string ATTRIBUTE = 'ATTRIBUTE';

    public const string VALUE = 'VALUE';

    public const string WARRANTY = 'WARRANTY';

    /**
     * @param  string  $written  as the file first wrote it — a category as its whole path, " / " between
     * @param  string  $key  keyOf() its names: a category's path, a value's attribute and itself
     * @param  string|null  $attribute  a value's attribute, as written
     * @param  AttributeKind|null  $attributeKind  an attribute's job, as the file uses it
     * @param  list<int>  $products  the numbers of the products using it
     * @param  int  $matches  how many catalog items answer to the name, when several do (amendment 8(d))
     */
    public function __construct(
        public string $kind,
        public string $written,
        public string $key,
        public ?string $attribute,
        public ?AttributeKind $attributeKind,
        public array $products,
        public int $matches = 0,
    ) {}

    /**
     * One name — or a path, or a value under its attribute — as names are compared, the same however
     * the file wrote it: hashed, since a category path has no length limit.
     *
     * @param  list<string>  $names
     */
    public static function keyOf(array $names): string
    {
        return hash('sha256', implode("\x1F", array_map(CatalogNames::key(...), $names)));
    }
}
