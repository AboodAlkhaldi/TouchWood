<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * A blog category (content.md §1.12): its name and address in both languages, its place in the store's order.
 */
final readonly class BlogCategory
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $nameAr,
        public string $nameEn,
        public string $slugAr,
        public string $slugEn,
        public int $position,
    ) {}

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return ['name_ar' => $this->nameAr, 'name_en' => $this->nameEn, 'slug_ar' => $this->slugAr, 'slug_en' => $this->slugEn];
    }
}
