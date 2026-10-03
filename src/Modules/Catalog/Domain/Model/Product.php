<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\AttributeSetLocked;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\ProductSlugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Public\Enums\ProductStage;

/**
 * A product (catalog.md §1.1): a family of purchasable configurations, **global** — one copy of its
 * data for every store. It keeps what one row can know; the rules that read other rows — a lowest,
 * active category, an active brand, a code no other product holds — are its handlers', under the
 * products' lock.
 *
 * **Created as a draft** with its Arabic name at least (amendment 3(g)); everything else may wait
 * until it is made ready. **Its attribute set is fixed once it has a variant** (§1.7). Its stage moves
 * draft → ready → archived → ready (§4.1), never back to draft.
 */
final class Product
{
    public const int DESCRIPTION_MAX = 20000;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private ProductName $name,
        private ProductSlugs $slugs,
        private ?StructuredText $descriptionAr,
        private ?StructuredText $descriptionEn,
        private string $brandId,
        private ?string $categoryId,
        private ?string $warrantyId,
        private ?string $attributeSetId,
        private ProductStage $stage,
    ) {
        $this->changes = new ChangeLog;
    }

    public static function create(string $id, ProductName $name, ProductSlugs $slugs, string $brandId): self
    {
        return new self($id, $name, $slugs, null, null, $brandId, null, null, null, ProductStage::Draft);
    }

    public static function reconstitute(
        string $id,
        ProductName $name,
        ProductSlugs $slugs,
        ?StructuredText $descriptionAr,
        ?StructuredText $descriptionEn,
        string $brandId,
        ?string $categoryId,
        ?string $warrantyId,
        ?string $attributeSetId,
        ProductStage $stage,
    ): self {
        return new self($id, $name, $slugs, $descriptionAr, $descriptionEn, $brandId, $categoryId, $warrantyId, $attributeSetId, $stage);
    }

    /**
     * The product's own form, sent whole.
     *
     * @param  bool  $hasVariants  whether any variant of it exists, read under the products' lock
     *
     * @throws AttributeSetLocked|InvalidCatalogAttribute
     */
    public function editDetails(
        ProductName $name,
        ProductSlugs $slugs,
        ?StructuredText $descriptionAr,
        ?StructuredText $descriptionEn,
        string $brandId,
        ?string $categoryId,
        ?string $warrantyId,
        ?string $attributeSetId,
        bool $hasVariants,
    ): void {
        if ($hasVariants && $attributeSetId !== $this->attributeSetId) {
            throw new AttributeSetLocked;
        }

        $before = $this->snapshot();
        $this->name = $name;
        $this->slugs = $slugs;
        $this->descriptionAr = $descriptionAr;
        $this->descriptionEn = $descriptionEn;
        $this->brandId = $brandId;
        $this->categoryId = $categoryId;
        $this->warrantyId = $warrantyId;
        $this->attributeSetId = $attributeSetId;

        foreach ($this->snapshot() as $column => $now) {
            $this->changes->record($column, $before[$column], $now);
        }
    }

    /**
     * Out of its draft (§4.1): its handler has checked every readiness rule. A ready product is left
     * as it is; an archived one changes only by being restored (§7).
     *
     * @throws ProductArchived
     */
    public function markReady(): void
    {
        if ($this->stage === ProductStage::Archived) {
            throw new ProductArchived;
        }

        $this->moveTo(ProductStage::Ready);
    }

    /**
     * Retired, a draft abandoned or a ready product (§4.1, §9.3 #19). One archived already stays so.
     */
    public function archive(): void
    {
        $this->moveTo(ProductStage::Archived);
    }

    /**
     * Back to ready (§4.1): only an archived product is restored, every readiness rule checked by its
     * handler; a ready one is left as it is, a draft is made ready instead.
     *
     * @throws InvalidStageChange
     */
    public function restore(): void
    {
        if ($this->stage === ProductStage::Draft) {
            throw new InvalidStageChange;
        }

        $this->moveTo(ProductStage::Ready);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): ProductName
    {
        return $this->name;
    }

    public function slugs(): ProductSlugs
    {
        return $this->slugs;
    }

    public function descriptionAr(): ?StructuredText
    {
        return $this->descriptionAr;
    }

    public function descriptionEn(): ?StructuredText
    {
        return $this->descriptionEn;
    }

    public function brandId(): string
    {
        return $this->brandId;
    }

    public function categoryId(): ?string
    {
        return $this->categoryId;
    }

    public function warrantyId(): ?string
    {
        return $this->warrantyId;
    }

    public function attributeSetId(): ?string
    {
        return $this->attributeSetId;
    }

    public function stage(): ProductStage
    {
        return $this->stage;
    }

    public function isDraft(): bool
    {
        return $this->stage === ProductStage::Draft;
    }

    /**
     * Every column by value, the descriptions as their exact JSON so a change of formatting alone is
     * a change.
     *
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->name->ar,
            'name_en' => $this->name->en,
            'slug_ar' => $this->slugs->ar->value,
            'slug_en' => $this->slugs->en?->value,
            'description_ar' => $this->descriptionAr?->toJson(),
            'description_en' => $this->descriptionEn?->toJson(),
            'brand_id' => $this->brandId,
            'category_id' => $this->categoryId,
            'warranty_id' => $this->warrantyId,
            'attribute_set_id' => $this->attributeSetId,
            'stage' => $this->stage->value,
        ];
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function pullChanges(): array
    {
        return $this->changes->pull();
    }

    private function moveTo(ProductStage $stage): void
    {
        $this->changes->record('stage', $this->stage->value, $stage->value);
        $this->stage = $stage;
    }
}
