<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * One entry of the Sync report (sync.md §1.6): what happened, when, and to what - a size of ours (its
 * product, to open and change it there) or a code the provider sent; a stopped sale names its sale.
 */
final readonly class ReportEntryView
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public int $id,
        public string $kind,
        public ?string $code,
        public ?string $variantId,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public ?string $saleId,
        public array $detail,
        public string $openedAt,
        public string $seenAt,
    ) {}

    /**
     * @param  array{id: int, kind: string, code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>, openedAt: string, seenAt: string}  $row
     * @param  array<string, array{productId: string, code: string, nameAr: string, nameEn: string|null}>  $names
     */
    public static function of(array $row, array $names): self
    {
        $size = $row['variantId'] === null ? null : ($names[$row['variantId']] ?? null);

        return new self(
            $row['id'],
            $row['kind'],
            $row['code'] ?? $size['code'] ?? null,
            $row['variantId'],
            $size['productId'] ?? null,
            $size['nameAr'] ?? null,
            $size['nameEn'] ?? null,
            $row['saleId'],
            $row['detail'],
            $row['openedAt'],
            $row['seenAt'],
        );
    }
}
