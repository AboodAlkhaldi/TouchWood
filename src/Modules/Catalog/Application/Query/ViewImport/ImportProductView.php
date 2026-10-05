<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * One product of the file (§1.12, parts 2–4): as it will come in, the catalog's product already
 * holding its codes and the decision on it, its state, the product it became and — a draft — what it
 * still lacks to be accepted, and the stores the file names for it.
 */
final readonly class ImportProductView
{
    /**
     * @param  list<string>  $codes
     * @param  array<string, string>|null  $newCodes
     * @param  list<string>  $missing  as `Readiness` names them
     * @param  list<ImportStoreView>  $stores
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public ?string $conflictProductId,
        public ?string $decision,
        public ?array $newCodes,
        public string $state,
        public ?string $productId,
        public bool $changed,
        public array $missing,
        public array $stores,
    ) {}
}
