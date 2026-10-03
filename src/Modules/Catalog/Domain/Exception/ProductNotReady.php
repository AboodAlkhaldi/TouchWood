<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Marking a product ready, or changing a ready one, without every readiness rule (catalog.md §1.1,
 * §7): it names what is missing — `name_en`, `slug_en`, `description_ar`, `description_en`,
 * `category`, `variants`, `photos`. The context carries them as one value, comma-separated: an error's
 * context holds plain values only, and the screens name each in their language.
 */
final class ProductNotReady extends CatalogError
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('The product is not ready: '.implode(', ', $this->missing).'.');
    }

    public function type(): string
    {
        return 'catalog.product_not_ready';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['missing' => implode(',', $this->missing)];
    }
}
