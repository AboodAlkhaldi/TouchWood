<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * What the products list is narrowed by (catalog.md §4.4 S8): words or a code, the stage, the
 * category, the brand, and - one store chosen - that store's state; and where the page starts.
 */
final readonly class ProductFilter
{
    public const string ON = 'ON';

    public const string OFF = 'OFF';

    public const string NOT_CHOSEN = 'NOT_CHOSEN';

    public const string NOT_AVAILABLE = 'NOT_AVAILABLE';

    /** @var list<string> */
    public const array STORE_STATES = [self::ON, self::OFF, self::NOT_CHOSEN, self::NOT_AVAILABLE];

    /**
     * @param  string|null  $search  a name in either language, or a code (digits 0-9)
     * @param  string|null  $storeId  the store whose state is shown, or none for All Stores
     * @param  string|null  $storeState  one of STORE_STATES, only with a store
     * @param  string|null  $after  the last product of the page before (keyset, newest first)
     */
    public function __construct(
        public ?string $search = null,
        public ?string $stage = null,
        public ?string $categoryId = null,
        public ?string $brandId = null,
        public ?string $storeId = null,
        public ?string $storeState = null,
        public ?string $after = null,
    ) {}
}
