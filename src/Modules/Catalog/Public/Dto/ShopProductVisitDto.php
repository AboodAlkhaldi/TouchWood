<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Shared\Domain\ValueObject\StoreId;

/**
 * Who is looking at which product's page, for a module's part of it (catalog.md amendment 26(b)):
 * the store and language of the page, the signed-in customer — none for a guest or anyone else — and
 * where each section was asked to go on from in its own list (`after`, from the address's
 * `after[{module}.{key}]`, every section's, so a module reading its sections together reads them
 * once), so "Show more" is the same page asked again.
 */
final readonly class ShopProductVisitDto
{
    /**
     * @param  'ar'|'en'  $locale
     * @param  array<string, string>  $after  section key => where it goes on from
     * @param  array<string, string>  $filters  section key => what it is narrowed to (amendment 31), the section's own word
     */
    public function __construct(
        public StoreId $store,
        public string $locale,
        public string $productId,
        public ?string $customerId,
        public array $after,
        public array $filters = [],
    ) {}
}
