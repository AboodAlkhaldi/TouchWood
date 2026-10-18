<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * One thing a read reported, as it was that day (sync.md §9.3 #19): what, and about which size - its
 * product named, to open it there - or code, sale or discount.
 */
final readonly class ReportedView
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $kind,
        public ?string $code,
        public ?string $variantId,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public ?string $saleId,
        public array $detail,
    ) {}
}
