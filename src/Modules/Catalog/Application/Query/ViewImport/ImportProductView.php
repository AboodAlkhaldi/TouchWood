<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * One product of the file (§1.12, parts 2–4): as it will come in, the catalog's product already
 * holding its codes and the decision on it — and, that product being on sale, whether it is kept on
 * sale or taken off (amendment 9(c)) —, its state, the product it became and — a draft — what it
 * still lacks to be accepted. One left out at upload is `REFUSED`, with why (amendment 11(a)); one
 * that would change a code its catalog product keeps is marked, to skip (`codeChange`, 11(b)).
 */
final readonly class ImportProductView
{
    /**
     * @param  list<string>  $codes
     * @param  array<string, string>|null  $newCodes
     * @param  list<string>  $missing  as `Readiness` names them
     * @param  list<string>  $addressTaken  the languages whose web address would collide: one is given here (amendment 8(c))
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
        public array $addressTaken,
        public bool $onSale,
        public ?string $sale,
        public ?string $refusal = null,
        public bool $codeChange = false,
    ) {}
}
