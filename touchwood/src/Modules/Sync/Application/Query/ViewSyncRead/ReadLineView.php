<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

/**
 * One price or stock number a read changed (sync.md §4.1 Y2): the size - its product to open it there,
 * its values and code, or that it no longer exists - and from → to, already written: a price as Pricing
 * writes money (the store's decimals and sign, Latin digits), a stock number as a whole number. No
 * "from" for a size the provider set first.
 */
final readonly class ReadLineView
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $variantId,
        public ?string $code,
        public bool $sizeExists,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public array $values,
        public ?string $before,
        public string $after,
    ) {}
}
