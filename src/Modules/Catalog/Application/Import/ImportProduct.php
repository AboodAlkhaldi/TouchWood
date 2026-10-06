<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * A product of a products file, as its import keeps it (catalog.md §5.5): as the file gave it, the
 * catalog's product already holding one of its codes, the Super Admin's decision on that, and what
 * became of it.
 */
final readonly class ImportProduct
{
    public const string UPDATE = 'UPDATE';

    public const string REPLACE = 'REPLACE';

    public const string SKIP = 'SKIP';

    public const string RECODE = 'RECODE';

    /** A product updated or replaced that is on sale stays on sale (amendment 9(c)). */
    public const string KEEP_ON_SALE = 'KEEP';

    /** … or is switched off in every store when brought in. */
    public const string TAKE_OFF_SALE = 'TAKE_OFF';

    /**
     * @param  list<string>  $codes
     * @param  array<string, string>|null  $newCodes  for RECODE: each code it gives up => its new one (a
     *                                                code of digits is an integer key, as PHP keeps it)
     */
    public function __construct(
        public string $id,
        public int $number,
        public FileProduct $product,
        public array $codes,
        public ?string $conflictProductId,
        public ?string $decision,
        public ?array $newCodes,
        public ?string $productId,
        public string $state,
        public ?FileProduct $edited = null,
        public ?string $sale = null,
    ) {}

    /** The product as it will come in: as the page's changes left it, or as the file gave it. */
    public function effective(): FileProduct
    {
        return $this->edited ?? $this->product;
    }

    public function changedTo(FileProduct $edited): self
    {
        return new self($this->id, $this->number, $this->product, $this->codes, $this->conflictProductId, $this->decision, $this->newCodes, $this->productId, $this->state, $edited, $this->sale);
    }

    /**
     * @param  array<string, string>|null  $newCodes
     */
    public function decided(string $decision, ?array $newCodes, ?string $sale = null): self
    {
        return new self($this->id, $this->number, $this->product, $this->codes, $this->conflictProductId, $decision, $newCodes, $this->productId, $this->state, $this->edited, $sale);
    }

    /**
     * Its codes as they will come in: a code it gives up replaced by its new one.
     *
     * @return list<string>
     */
    public function codesComingIn(): array
    {
        return array_values(array_unique(array_map(fn (string $code): string => $this->newCodes[$code] ?? $code, $this->codes)));
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'number' => $this->number,
            'codes' => implode(', ', $this->codes),
            'conflict_product_id' => $this->conflictProductId,
            'decision' => $this->decision,
            'sale' => $this->sale,
            'new_codes' => $this->newCodes === null ? null : implode(', ', array_map(static fn (int|string $from, string $to): string => "{$from} → {$to}", array_keys($this->newCodes), $this->newCodes)),
        ];
    }
}
