<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\Combination;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Domain\ValueObject\VariantDetail;
use Modules\Catalog\Domain\ValueObject\VariantMeasures;

/**
 * One purchasable configuration of a product — a length, a finish (catalog.md §1.2). It never moves
 * to another product. **Its code is its product's** (amendment 3(e)): which codes a product holds,
 * and whether another product holds one, its handlers ask under the products' lock. **Its values
 * stay editable**, a ready product's too (amendment 3(j)); two variants of one product never share a
 * combination — the repository's question.
 */
final class Variant
{
    private ChangeLog $changes;

    /**
     * @param  array<string, VariantDetail>  $details  attribute id => its value, details-only attributes
     */
    private function __construct(
        private readonly string $id,
        private readonly string $productId,
        private ProductCode $code,
        private Combination $combination,
        private array $details,
        private VariantMeasures $measures,
        private bool $isArchived,
        private int $position,
    ) {
        $this->changes = new ChangeLog;
        ksort($this->details);
    }

    /**
     * @param  array<string, VariantDetail>  $details
     *
     * @throws InvalidCatalogAttribute
     */
    public static function add(string $id, string $productId, ProductCode $code, Combination $combination, array $details, VariantMeasures $measures, int $position): self
    {
        return new self($id, $productId, $code, $combination, $details, $measures, false, ListPosition::check($position));
    }

    /**
     * @param  array<string, VariantDetail>  $details
     */
    public static function reconstitute(string $id, string $productId, ProductCode $code, Combination $combination, array $details, VariantMeasures $measures, bool $isArchived, int $position): self
    {
        return new self($id, $productId, $code, $combination, $details, $measures, $isArchived, $position);
    }

    /**
     * @param  array<string, VariantDetail>  $details
     *
     * @throws InvalidCatalogAttribute
     */
    public function edit(Combination $combination, array $details, VariantMeasures $measures, int $position): void
    {
        $position = ListPosition::check($position);
        $before = $this->snapshot();
        $this->combination = $combination;
        ksort($details);
        $this->details = $details;
        $this->measures = $measures;
        $this->position = $position;
        $this->recordAgainst($before);
    }

    /** Archived on its own, product-wide (§1.2): Inactive in every store, its code still its product's. */
    public function archive(): void
    {
        $this->changes->record('is_archived', $this->isArchived, true);
        $this->isArchived = true;
    }

    public function restore(): void
    {
        $this->changes->record('is_archived', $this->isArchived, false);
        $this->isArchived = false;
    }

    public function changeCode(ProductCode $code): void
    {
        $this->changes->record('code', $this->code->value, $code->value);
        $this->code = $code;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function code(): ProductCode
    {
        return $this->code;
    }

    public function combination(): Combination
    {
        return $this->combination;
    }

    /**
     * @return array<string, VariantDetail>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function measures(): VariantMeasures
    {
        return $this->measures;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * Every column by value; the values and details each as one value, so a change to any of them is
     * a change.
     *
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'code' => $this->code->value,
            'value_ids' => $this->combination->key(),
            'details' => $this->details === [] ? null : json_encode(array_map(
                static fn (VariantDetail $detail): array => array_filter(['text_ar' => $detail->textAr, 'text_en' => $detail->textEn, 'number' => $detail->number], static fn (?string $part): bool => $part !== null),
                $this->details,
            ), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'weight_grams' => $this->measures->weightGrams,
            'length_mm' => $this->measures->lengthMm,
            'width_mm' => $this->measures->widthMm,
            'height_mm' => $this->measures->heightMm,
            'is_archived' => $this->isArchived,
            'position' => $this->position,
        ];
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function pullChanges(): array
    {
        return $this->changes->pull();
    }

    /**
     * @param  array<string, string|int|bool|null>  $before
     */
    private function recordAgainst(array $before): void
    {
        foreach ($this->snapshot() as $column => $now) {
            $this->changes->record($column, $before[$column], $now);
        }
    }
}
