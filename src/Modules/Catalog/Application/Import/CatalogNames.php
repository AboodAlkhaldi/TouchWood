<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Model\AttributeSet;
use Modules\Catalog\Domain\Model\Brand;
use Modules\Catalog\Domain\Model\Category;
use Modules\Catalog\Domain\Model\Warranty;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * **The catalog's names, as a file names them** (catalog.md §1.12; the guide, §1.3): each brand,
 * category, attribute, value, set and warranty found by its Arabic or its English name, as search
 * compares words. A category is found by its path from the top, each level by its own name among
 * its siblings. Read once for a whole file; deactivated ones are found too — the change that uses one
 * says it is off.
 */
final readonly class CatalogNames
{
    /**
     * @param  array<string, string>  $brands  key => id
     * @param  array<string, list<Category>>  $children  parent id ('' for the top) => its categories
     * @param  array<string, Attribute>  $attributes  key => attribute
     * @param  array<string, array<string, string>>  $values  attribute id => key => value id
     * @param  array<string, string>  $sets  key => id
     * @param  array<string, list<string>>  $members  set id => its attributes' ids, in order
     * @param  array<string, string>  $warranties  key => id
     */
    private function __construct(
        private array $brands,
        private array $children,
        private array $attributes,
        private array $values,
        private array $sets,
        private array $members,
        private array $warranties,
    ) {}

    public static function load(BrandRepository $brands, CategoryRepository $categories, AttributeRepository $attributes, WarrantyRepository $warranties): self
    {
        $children = [];

        foreach ($categories->all() as $category) {
            $children[$category->parentId() ?? ''][] = $category;
        }

        $byKey = [];
        $values = [];

        foreach ($attributes->all() as $attribute) {
            foreach (self::keys($attribute->name()->ar, $attribute->name()->en) as $key) {
                $byKey[$key] ??= $attribute;
            }

            foreach ($attributes->valuesOf($attribute->id()) as $value) {
                foreach (self::keys($value->name()->ar, $value->name()->en) as $key) {
                    $values[$attribute->id()][$key] ??= $value->id();
                }
            }
        }

        $sets = $attributes->sets();
        $members = [];

        foreach ($sets as $set) {
            $members[$set->id()] = $set->memberIds();
        }

        return new self(
            self::index(array_map(static fn (Brand $brand): array => [$brand->id(), $brand->name()], $brands->all())),
            $children,
            $byKey,
            $values,
            self::index(array_map(static fn (AttributeSet $set): array => [$set->id(), $set->name()], $sets)),
            $members,
            self::index(array_map(static fn (Warranty $warranty): array => [$warranty->id(), $warranty->name()], $warranties->all())),
        );
    }

    /** As names are compared: letter case, Arabic marks and letter forms aside. */
    public static function key(string $name): string
    {
        return ArabicText::normalize($name);
    }

    public function brand(string $name): ?string
    {
        return $this->brands[self::key($name)] ?? null;
    }

    /**
     * @param  list<string>  $path  from the top
     */
    public function category(array $path): ?string
    {
        $parent = '';

        foreach ($path as $name) {
            $key = self::key($name);
            $found = null;

            foreach ($this->children[$parent] ?? [] as $category) {
                if (in_array($key, self::keys($category->name()->ar, $category->name()->en), true)) {
                    $found = $category->id();

                    break;
                }
            }

            if ($found === null) {
                return null;
            }

            $parent = $found;
        }

        return $parent === '' ? null : $parent;
    }

    /** Whether the category has categories under it — a product's must have none (§1.5). */
    public function hasChildren(string $categoryId): bool
    {
        return ($this->children[$categoryId] ?? []) !== [];
    }

    public function attribute(string $name): ?Attribute
    {
        return $this->attributes[self::key($name)] ?? null;
    }

    public function attributeKind(string $name): ?AttributeKind
    {
        return $this->attribute($name)?->kind();
    }

    public function value(string $attributeId, string $name): ?string
    {
        return $this->values[$attributeId][self::key($name)] ?? null;
    }

    public function set(string $name): ?string
    {
        return $this->sets[self::key($name)] ?? null;
    }

    /**
     * @return list<string> the set's attributes' ids, in order
     */
    public function setMembers(string $setId): array
    {
        return $this->members[$setId] ?? [];
    }

    public function warranty(string $name): ?string
    {
        return $this->warranties[self::key($name)] ?? null;
    }

    /**
     * @param  list<array{string, LocalizedName}>  $items  id and name
     * @return array<string, string>
     */
    private static function index(array $items): array
    {
        $index = [];

        foreach ($items as [$id, $name]) {
            foreach (self::keys($name->ar, $name->en) as $key) {
                $index[$key] ??= $id;
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private static function keys(string $ar, ?string $en): array
    {
        return array_values(array_unique(array_filter([self::key($ar), $en === null ? '' : self::key($en)], static fn (string $key): bool => $key !== '')));
    }
}
