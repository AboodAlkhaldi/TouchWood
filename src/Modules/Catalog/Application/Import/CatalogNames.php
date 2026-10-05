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
 *
 * **A name answers only when one item does** (amendment 8(d)): names need not be unique, and the
 * comparison folds more than the database's own, so a name several items answer to finds none here —
 * the import's page asks which, and `*Matches()` says how many.
 */
final readonly class CatalogNames
{
    /**
     * @param  array<string, list<string>>  $brands  key => ids
     * @param  array<string, list<Category>>  $children  parent id ('' for the top) => its categories
     * @param  array<string, list<Attribute>>  $attributes  key => attributes
     * @param  array<string, array<string, list<string>>>  $values  attribute id => key => value ids
     * @param  array<string, list<string>>  $sets  key => ids
     * @param  array<string, list<string>>  $members  set id => its attributes' ids, in order
     * @param  array<string, list<string>>  $warranties  key => ids
     * @param  array<int, string>  $brandNumbers  a brand's fixed number => its id
     */
    private function __construct(
        private array $brands,
        private array $children,
        private array $attributes,
        private array $values,
        private array $sets,
        private array $members,
        private array $warranties,
        private array $brandNumbers,
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
                $byKey[$key][] = $attribute;
            }

            $values[$attribute->id()] = self::index(array_map(static fn ($value): array => [$value->id(), $value->name()], $attributes->valuesOf($attribute->id())));
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
            $brands->numbers(),
        );
    }

    /** As names are compared: letter case, Arabic marks and letter forms aside. */
    public static function key(string $name): string
    {
        return ArabicText::normalize($name);
    }

    public function brand(string $name): ?string
    {
        return self::one($this->brands[self::key($name)] ?? []);
    }

    public function brandMatches(string $name): int
    {
        return count($this->brands[self::key($name)] ?? []);
    }

    /** The brand with this fixed number (§1.6, amendment 7(b)): never more than one. */
    public function brandNumbered(int $number): ?string
    {
        return $this->brandNumbers[$number] ?? null;
    }

    /**
     * @param  list<string>  $path  from the top
     */
    public function category(array $path): ?string
    {
        $parent = '';

        foreach ($path as $name) {
            $found = self::one(array_map(static fn (Category $category): string => $category->id(), $this->siblings($parent, $name)));

            if ($found === null) {
                return null;
            }

            $parent = $found;
        }

        return $parent === '' ? null : $parent;
    }

    /**
     * How many categories answer to the path's last level, under its parent when that one is found.
     *
     * @param  list<string>  $path  from the top
     */
    public function categoryMatches(array $path): int
    {
        $last = array_pop($path);
        $parent = $path === [] ? '' : $this->category($path);

        return $last === null || $parent === null ? 0 : count($this->siblings($parent, $last));
    }

    /** Whether the category has categories under it — a product's must have none (§1.5). */
    public function hasChildren(string $categoryId): bool
    {
        return ($this->children[$categoryId] ?? []) !== [];
    }

    public function attribute(string $name): ?Attribute
    {
        $found = $this->attributes[self::key($name)] ?? [];

        return count($found) === 1 ? $found[0] : null;
    }

    public function attributeMatches(string $name): int
    {
        return count($this->attributes[self::key($name)] ?? []);
    }

    public function attributeKind(string $name): ?AttributeKind
    {
        return $this->attribute($name)?->kind();
    }

    public function value(string $attributeId, string $name): ?string
    {
        return self::one($this->values[$attributeId][self::key($name)] ?? []);
    }

    public function valueMatches(string $attributeId, string $name): int
    {
        return count($this->values[$attributeId][self::key($name)] ?? []);
    }

    public function set(string $name): ?string
    {
        return self::one($this->sets[self::key($name)] ?? []);
    }

    public function setMatches(string $name): int
    {
        return count($this->sets[self::key($name)] ?? []);
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
        return self::one($this->warranties[self::key($name)] ?? []);
    }

    public function warrantyMatches(string $name): int
    {
        return count($this->warranties[self::key($name)] ?? []);
    }

    /**
     * @return list<Category> the categories under the parent ('' for the top) answering to the name
     */
    private function siblings(string $parent, string $name): array
    {
        $key = self::key($name);

        return array_values(array_filter($this->children[$parent] ?? [], static fn (Category $category): bool => in_array($key, self::keys($category->name()->ar, $category->name()->en), true)));
    }

    /**
     * @param  list<string>  $ids
     */
    private static function one(array $ids): ?string
    {
        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * @param  list<array{string, LocalizedName}>  $items  id and name
     * @return array<string, list<string>> key => the ids answering to it, each once
     */
    private static function index(array $items): array
    {
        $index = [];

        foreach ($items as [$id, $name]) {
            foreach (self::keys($name->ar, $name->en) as $key) {
                $index[$key][] = $id;
            }
        }

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $index);
    }

    /**
     * @return list<string>
     */
    private static function keys(string $ar, ?string $en): array
    {
        return array_values(array_unique(array_filter([self::key($ar), $en === null ? '' : self::key($en)], static fn (string $key): bool => $key !== '')));
    }
}
