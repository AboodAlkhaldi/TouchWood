<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewProduct;

/**
 * One product's page (catalog.md §4.4 S9), with what its open tab shows: the page reads one tab at a
 * time, so each stays within the admin budget (frontend.md §5).
 */
final readonly class ViewProduct
{
    public const string DETAILS = 'details';

    public const string VARIANTS = 'variants';

    public const string PHOTOS = 'photos';

    public const string SEARCH = 'search';

    public const string RELATED = 'related';

    /** @var list<string> */
    public const array TABS = [self::DETAILS, self::VARIANTS, self::PHOTOS, self::SEARCH, self::RELATED];

    public function __construct(
        public string $productId,
        public string $tab = self::DETAILS,
    ) {}
}
