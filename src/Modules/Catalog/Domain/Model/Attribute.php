<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\AttributeKindLocked;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\CatalogText;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * An attribute, defined once for the whole catalog (catalog.md §1.7, owner 2026-10-02: one shared
 * library): its name, **its job** — shown in the details only, offered as a filter, or making
 * variants (handoff §9.1) — an optional unit (mm, kg), and whether it is a colour, whose values then
 * carry a swatch (the design's Colours library).
 *
 * **The job, and being a colour, change only while the attribute has no values** (amendment 1(i)):
 * a value list built for one job, and the variants built on it, would mean something else under
 * another. Only a filter or a variant-making attribute has values, and only such an attribute can be
 * a colour.
 */
final class Attribute
{
    public const int NAME_MAX = 100;

    public const int UNIT_MAX = 20;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private LocalizedName $name,
        private AttributeKind $kind,
        private ?string $unitAr,
        private ?string $unitEn,
        private bool $isColour,
        private int $position,
        private bool $isActive,
    ) {
        $this->changes = new ChangeLog;
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function add(string $id, LocalizedName $name, AttributeKind $kind, ?string $unitAr, ?string $unitEn, bool $isColour, int $position): self
    {
        [$unitAr, $unitEn] = self::unit($unitAr, $unitEn);
        self::checkColour($kind, $isColour);

        return new self($id, $name, $kind, $unitAr, $unitEn, $isColour, ListPosition::check($position), true);
    }

    public static function reconstitute(string $id, LocalizedName $name, AttributeKind $kind, ?string $unitAr, ?string $unitEn, bool $isColour, int $position, bool $isActive): self
    {
        return new self($id, $name, $kind, $unitAr, $unitEn, $isColour, $position, $isActive);
    }

    /**
     * @param  bool  $hasValues  whether any value of this attribute exists, read under the lock
     *
     * @throws AttributeKindLocked|InvalidCatalogAttribute
     */
    public function edit(LocalizedName $name, AttributeKind $kind, ?string $unitAr, ?string $unitEn, bool $isColour, int $position, bool $hasValues): void
    {
        if ($hasValues && ($kind !== $this->kind || $isColour !== $this->isColour)) {
            throw new AttributeKindLocked;
        }

        [$unitAr, $unitEn] = self::unit($unitAr, $unitEn);
        self::checkColour($kind, $isColour);
        $before = $this->snapshot();

        $this->name = $name;
        $this->kind = $kind;
        $this->unitAr = $unitAr;
        $this->unitEn = $unitEn;
        $this->isColour = $isColour;
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

    public function name(): LocalizedName
    {
        return $this->name;
    }

    public function kind(): AttributeKind
    {
        return $this->kind;
    }

    public function unitAr(): ?string
    {
        return $this->unitAr;
    }

    public function unitEn(): ?string
    {
        return $this->unitEn;
    }

    public function isColour(): bool
    {
        return $this->isColour;
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
            'kind' => $this->kind->value,
            'unit_ar' => $this->unitAr,
            'unit_en' => $this->unitEn,
            'is_colour' => $this->isColour,
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
     * A unit in both languages, or in neither.
     *
     * @return array{?string, ?string}
     *
     * @throws InvalidCatalogAttribute
     */
    private static function unit(?string $ar, ?string $en): array
    {
        $ar = CatalogText::optionalLine('unit_ar', $ar, self::UNIT_MAX);
        $en = CatalogText::optionalLine('unit_en', $en, self::UNIT_MAX);

        if (($ar === null) !== ($en === null)) {
            throw new InvalidCatalogAttribute($ar === null ? 'unit_ar' : 'unit_en', 'in both languages, or in neither');
        }

        return [$ar, $en];
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function checkColour(AttributeKind $kind, bool $isColour): void
    {
        if ($isColour && ! $kind->hasValues()) {
            throw new InvalidCatalogAttribute('is_colour', 'a filter or a variant-making attribute');
        }
    }
}
