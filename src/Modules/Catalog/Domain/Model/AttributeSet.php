<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * An attribute set — the design's **Variations**, "attribute sets, measurement and finish, that
 * generate variants" (catalog.md §1.7): a named, ordered group of **variant-making attributes**. A
 * product takes one set, and its variants are combinations of those attributes' values.
 *
 * Its members are given as attributes read now, so the rules are checked against what they are:
 * at least one, none twice, every one variant-making, and every one it newly takes active.
 */
final class AttributeSet
{
    public const int NAME_MAX = 100;

    /** More attributes than any product varies by. */
    public const int MAX_MEMBERS = 10;

    private ChangeLog $changes;

    /**
     * @param  list<string>  $memberIds  in their order
     */
    private function __construct(
        private readonly string $id,
        private LocalizedName $name,
        private array $memberIds,
        private bool $isActive,
    ) {
        $this->changes = new ChangeLog;
    }

    /**
     * @param  list<Attribute>  $members
     *
     * @throws InvalidCatalogAttribute|ListItemInactive
     */
    public static function add(string $id, LocalizedName $name, array $members): self
    {
        return new self($id, $name, self::members($members), true);
    }

    /**
     * @param  list<string>  $memberIds
     */
    public static function reconstitute(string $id, LocalizedName $name, array $memberIds, bool $isActive): self
    {
        return new self($id, $name, $memberIds, $isActive);
    }

    /**
     * @param  list<Attribute>  $members
     *
     * @throws InvalidCatalogAttribute|ListItemInactive
     */
    public function edit(LocalizedName $name, array $members): void
    {
        $before = $this->snapshot();
        $this->name = $name;
        $this->memberIds = self::members($members, $this->memberIds);

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

    /**
     * @return list<string> in their order
     */
    public function memberIds(): array
    {
        return $this->memberIds;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * The members as one value, in order, so a reorder is a change too.
     *
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->name->ar,
            'name_en' => $this->name->en,
            'attribute_ids' => implode(',', $this->memberIds),
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
     * @param  list<Attribute>  $members
     * @param  list<string>  $held  the members it has: one deactivated since may stay, so the set
     *                              can still be renamed; it is only never added again
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute|ListItemInactive
     */
    private static function members(array $members, array $held = []): array
    {
        if ($members === []) {
            throw new InvalidCatalogAttribute('attribute_ids', 'at least one attribute');
        }

        if (count($members) > self::MAX_MEMBERS) {
            throw new InvalidCatalogAttribute('attribute_ids', 'at most '.self::MAX_MEMBERS.' attributes');
        }

        $ids = [];

        foreach ($members as $attribute) {
            if ($attribute->kind() !== AttributeKind::Variant) {
                throw new InvalidCatalogAttribute('attribute_ids', 'variant-making attributes only');
            }

            if (! $attribute->isActive() && ! in_array($attribute->id(), $held, true)) {
                throw new ListItemInactive;
            }

            if (in_array($attribute->id(), $ids, true)) {
                throw new InvalidCatalogAttribute('attribute_ids', 'each attribute once');
            }

            $ids[] = $attribute->id();
        }

        return $ids;
    }
}
