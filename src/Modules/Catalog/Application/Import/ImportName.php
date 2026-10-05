<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * A name a products file uses that the catalog lacked, as its import keeps it (catalog.md §5.5), with
 * the Super Admin's decision once taken.
 */
final readonly class ImportName
{
    public const string EXISTING = 'EXISTING';

    public const string CREATE = 'CREATE';

    public const string REFUSE = 'REFUSE';

    public function __construct(
        public string $id,
        public string $kind,
        public string $written,
        public string $key,
        public ?string $attribute,
        public ?AttributeKind $attributeKind,
        public ?string $decision,
        public ?string $targetId,
        public ?string $nameAr,
        public ?string $nameEn,
        public int $products,
    ) {}

    public function decided(string $decision, ?string $targetId, ?string $nameAr, ?string $nameEn): self
    {
        return new self($this->id, $this->kind, $this->written, $this->key, $this->attribute, $this->attributeKind, $decision, $targetId, $nameAr, $nameEn, $this->products);
    }

    /** Waiting for a decision again. */
    public function undecided(): self
    {
        return new self($this->id, $this->kind, $this->written, $this->key, $this->attribute, $this->attributeKind, null, null, null, null, $this->products);
    }

    /**
     * What the audit log keeps of it — the business's words, by value.
     *
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'kind' => $this->kind,
            'written' => $this->written,
            'attribute' => $this->attribute,
            'decision' => $this->decision,
            'target_id' => $this->targetId,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
        ];
    }
}
