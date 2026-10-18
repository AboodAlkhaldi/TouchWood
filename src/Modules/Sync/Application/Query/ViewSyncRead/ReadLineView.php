<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

/**
 * One price or stock number a read changed, from and to, naming the size's product to open it there.
 */
final readonly class ReadLineView
{
    public function __construct(
        public string $variantId,
        public ?string $code,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public ?string $before,
        public string $after,
    ) {}
}
