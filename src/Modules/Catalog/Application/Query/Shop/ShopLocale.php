<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * The shop's two languages (handoff §5.2): a page is read in one of them, never another.
 */
final class ShopLocale
{
    /**
     * @return 'ar'|'en'
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $locale): string
    {
        return match ($locale) {
            'ar' => 'ar',
            'en' => 'en',
            default => throw new InvalidCatalogAttribute('locale', 'ar or en'),
        };
    }
}
