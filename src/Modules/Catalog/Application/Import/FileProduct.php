<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One product of a product file, as read and checked (the guide, §1.1). Its descriptions are already
 * the structured text the panel keeps; every name of a list — brand, category path, warranty, set,
 * attributes and values — stays as written, for the import's page to match (catalog.md §1.12).
 */
final readonly class FileProduct
{
    /**
     * @param  int  $number  its place in the file, counted from 1
     * @param  array{blocks: list<array<string, mixed>>}|null  $descriptionAr
     * @param  array{blocks: list<array<string, mixed>>}|null  $descriptionEn
     * @param  list<string>|null  $category  the path of names, from the top
     * @param  list<FileVariant>  $variants
     * @param  list<string>  $photos  the gallery's paths inside the zip
     * @param  list<string>  $searchWords
     * @param  array<string, list<string>>  $filters  filter attribute name => value names
     * @param  list<string>  $related  codes
     * @param  list<string>  $goesWith  codes
     * @param  array<string, array{price: string|null, stock: int|null}>  $stores  store code => as the file gave it
     * @param  int|null  $brandNumber  the brand's fixed number, when the file gave a number instead of a name (amendment 7(b))
     * @param  string|null  $warrantyId  picked on the import's page (amendment 7(c)): it stands for the warranty's name
     * @param  string|null  $categoryId  picked on the import's page: it stands for the category's path
     * @param  list<string>  $filterValueIds  picked on the import's page, beside the filters the file names
     */
    public function __construct(
        public int $number,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $slugAr,
        public ?string $slugEn,
        public ?array $descriptionAr,
        public ?array $descriptionEn,
        public ?string $brand,
        public ?array $category,
        public ?string $warranty,
        public ?string $attributeSet,
        public array $variants,
        public array $photos,
        public array $searchWords,
        public array $filters,
        public array $related,
        public array $goesWith,
        public array $stores,
        public ?int $brandNumber = null,
        public ?string $warrantyId = null,
        public ?string $categoryId = null,
        public array $filterValueIds = [],
    ) {}

    /**
     * A copy with these fields changed, named as toArray() names them.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray([...$this->toArray(), ...$changes]);
    }

    /**
     * Its codes, each once, in the order its variants give them.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(static fn (FileVariant $variant): string => $variant->code, $this->variants)));
    }

    /**
     * Every photo it names, the gallery's and its variants', each once.
     *
     * @return list<string>
     */
    public function allPhotos(): array
    {
        $paths = $this->photos;

        foreach ($this->variants as $variant) {
            array_push($paths, ...$variant->photos);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'slug_ar' => $this->slugAr,
            'slug_en' => $this->slugEn,
            'description_ar' => $this->descriptionAr,
            'description_en' => $this->descriptionEn,
            'brand' => $this->brand,
            'category' => $this->category,
            'warranty' => $this->warranty,
            'attribute_set' => $this->attributeSet,
            'variants' => array_map(static fn (FileVariant $variant): array => $variant->toArray(), $this->variants),
            'photos' => $this->photos,
            'search_words' => $this->searchWords,
            'filters' => $this->filters,
            'related' => $this->related,
            'goes_with' => $this->goesWith,
            'stores' => $this->stores,
            'brand_number' => $this->brandNumber,
            'warranty_id' => $this->warrantyId,
            'category_id' => $this->categoryId,
            'filter_value_ids' => $this->filterValueIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  as toArray() wrote it
     */
    public static function fromArray(array $data): self
    {
        /** @var array{blocks: list<array<string, mixed>>}|null $descriptionAr */
        $descriptionAr = $data['description_ar'] ?? null;
        /** @var array{blocks: list<array<string, mixed>>}|null $descriptionEn */
        $descriptionEn = $data['description_en'] ?? null;
        /** @var list<string>|null $category */
        $category = $data['category'] ?? null;
        /** @var list<array<string, mixed>> $variants */
        $variants = $data['variants'] ?? [];
        /** @var list<string> $photos */
        $photos = $data['photos'] ?? [];
        /** @var list<string> $searchWords */
        $searchWords = $data['search_words'] ?? [];
        /** @var array<string, list<string>> $filters */
        $filters = $data['filters'] ?? [];
        /** @var list<string> $related */
        $related = $data['related'] ?? [];
        /** @var list<string> $goesWith */
        $goesWith = $data['goes_with'] ?? [];
        /** @var array<string, array{price: string|null, stock: int|null}> $stores */
        $stores = $data['stores'] ?? [];
        /** @var list<string> $filterValueIds */
        $filterValueIds = $data['filter_value_ids'] ?? [];

        return new self(
            (int) $data['number'],
            (string) $data['name_ar'],
            self::text($data['name_en'] ?? null),
            self::text($data['slug_ar'] ?? null),
            self::text($data['slug_en'] ?? null),
            $descriptionAr,
            $descriptionEn,
            self::text($data['brand'] ?? null),
            $category,
            self::text($data['warranty'] ?? null),
            self::text($data['attribute_set'] ?? null),
            array_map(FileVariant::fromArray(...), $variants),
            $photos,
            $searchWords,
            $filters,
            $related,
            $goesWith,
            $stores,
            is_int($data['brand_number'] ?? null) ? $data['brand_number'] : null,
            self::text($data['warranty_id'] ?? null),
            self::text($data['category_id'] ?? null),
            $filterValueIds,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
