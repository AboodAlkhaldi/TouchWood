<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\CatalogText;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\LocalizedName;

/**
 * One value of a filter or a variant-making attribute — "Black", "300 mm" (catalog.md §1.7). Two
 * values of one attribute never share a name in either language, after trimming and ignoring
 * letter case ("Black" and "black" are one value, owner 2026-10-02): a question about the other
 * rows, so the repository answers it.
 *
 * **A colour attribute's values carry a swatch**, `#rrggbb` — the design's Colours library — and
 * only theirs do.
 */
final class AttributeValue
{
    public const int NAME_MAX = 100;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private readonly string $attributeId,
        private LocalizedName $name,
        private ?string $swatch,
        private int $position,
        private bool $isActive,
    ) {
        $this->changes = new ChangeLog;
    }

    /**
     * @param  Attribute  $attribute  the attribute it joins, read under the attributes' lock
     *
     * @throws InvalidCatalogAttribute
     */
    public static function add(string $id, Attribute $attribute, LocalizedName $name, ?string $swatch, int $position): self
    {
        if (! $attribute->kind()->hasValues()) {
            throw new InvalidCatalogAttribute('attribute', 'a filter or a variant-making attribute: one shown in the details only has no list of values');
        }

        return new self($id, $attribute->id(), $name, self::checkedSwatch($attribute, $swatch), ListPosition::check($position), true);
    }

    public static function reconstitute(string $id, string $attributeId, LocalizedName $name, ?string $swatch, int $position, bool $isActive): self
    {
        return new self($id, $attributeId, $name, $swatch, $position, $isActive);
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public function edit(Attribute $attribute, LocalizedName $name, ?string $swatch, int $position): void
    {
        $before = $this->snapshot();
        $this->name = $name;
        $this->swatch = self::checkedSwatch($attribute, $swatch);
        $this->position = ListPosition::check($position);

        foreach ($this->snapshot() as $column => $now) {
            $this->changes->record($column, $before[$column], $now);
        }
    }

    public function deactivate(): void
    {
        $this->changes->record('is_active', $this->isActive, false);
        $this->isActive = false;
    }

    public function activate(): void
    {
        $this->changes->record('is_active', $this->isActive, true);
        $this->isActive = true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function attributeId(): string
    {
        return $this->attributeId;
    }

    public function name(): LocalizedName
    {
        return $this->name;
    }

    public function swatch(): ?string
    {
        return $this->swatch;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->name->ar,
            'name_en' => $this->name->en,
            'swatch' => $this->swatch,
            'position' => $this->position,
            'is_active' => $this->isActive,
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
     * Required on a colour attribute's value, refused on any other.
     *
     * @throws InvalidCatalogAttribute
     */
    private static function checkedSwatch(Attribute $attribute, ?string $swatch): ?string
    {
        $swatch = CatalogText::optionalLine('swatch', $swatch, 7);

        if (! $attribute->isColour()) {
            return $swatch === null ? null : throw new InvalidCatalogAttribute('swatch', 'only on a colour attribute\'s values');
        }

        if ($swatch === null || preg_match('/\A#[0-9a-f]{6}\z/i', $swatch) !== 1) {
            throw new InvalidCatalogAttribute('swatch', 'a colour written #rrggbb');
        }

        return strtolower($swatch);
    }
}
