<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\CategoryLoop;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slugs;

/**
 * A category in the **one global tree** (handoff §9.3, catalog.md §1.5), nesting without limit.
 * The tree's rules that read more than one row — a parent that holds products takes no
 * sub-category, a category never moves under anything below it — are the handlers', given the tree;
 * this class keeps what one row can know.
 *
 * **Deactivating** takes the category's sub-categories with it, and each of them remembers it went
 * because its parent did (`deactivated_with_parent`), so activating the parent brings back exactly
 * what was active before (catalog.md §1.5) — and a sub-category deactivated on its own stays so.
 */
final class Category
{
    public const int NAME_MAX = 100;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private ?string $parentId,
        private LocalizedName $name,
        private Slugs $slugs,
        private ?string $imageMediaId,
        private bool $isActive,
        private bool $deactivatedWithParent,
        // The line under its shop page's heading, per language, both optional (shop-design.md §10.5).
        private ?string $descriptionAr = null,
        private ?string $descriptionEn = null,
    ) {
        $this->changes = new ChangeLog;
    }

    public static function add(string $id, ?string $parentId, LocalizedName $name, Slugs $slugs, ?string $imageMediaId): self
    {
        return new self($id, $parentId, $name, $slugs, $imageMediaId, true, false);
    }

    public static function reconstitute(string $id, ?string $parentId, LocalizedName $name, Slugs $slugs, ?string $imageMediaId, bool $isActive, bool $deactivatedWithParent, ?string $descriptionAr = null, ?string $descriptionEn = null): self
    {
        return new self($id, $parentId, $name, $slugs, $imageMediaId, $isActive, $deactivatedWithParent, $descriptionAr, $descriptionEn);
    }

    /**
     * @param  array{ar: string|null, en: string|null}|null  $description  none keeps it: the callers that predate it send none
     */
    public function edit(LocalizedName $name, Slugs $slugs, ?string $imageMediaId, ?array $description = null): void
    {
        $before = $this->snapshot();
        $this->name = $name;
        $this->slugs = $slugs;
        $this->imageMediaId = $imageMediaId;

        if ($description !== null) {
            $this->descriptionAr = $description['ar'];
            $this->descriptionEn = $description['en'];
        }

        $this->recordAgainst($before);
    }

    public function descriptionAr(): ?string
    {
        return $this->descriptionAr;
    }

    public function descriptionEn(): ?string
    {
        return $this->descriptionEn;
    }

    /**
     * A category that went with its parent and moves away from it stays deactivated, now on its
     * own: the parent it went with is no longer above it, and activating its new parent must not
     * bring back a category that was off before that parent went (catalog.md §1.5).
     *
     * @param  list<string>  $below  every category under this one, at any depth
     *
     * @throws CategoryLoop
     */
    public function moveUnder(?string $parentId, array $below): void
    {
        if ($parentId !== null && ($parentId === $this->id || in_array($parentId, $below, true))) {
            throw new CategoryLoop;
        }

        if ($parentId === $this->parentId) {
            return;
        }

        $this->changes->record('parent_id', $this->parentId, $parentId);
        $this->parentId = $parentId;

        if (! $this->isActive && $this->deactivatedWithParent) {
            $this->changes->record('deactivated_with_parent', true, false);
            $this->deactivatedWithParent = false;
        }
    }

    /**
     * Deactivated by staff (`$withParent` false), or because the category above went (`true`).
     * A category already inactive keeps the reason it has.
     */
    public function deactivate(bool $withParent): void
    {
        if (! $this->isActive) {
            return;
        }

        $this->changes->record('is_active', true, false);
        $this->changes->record('deactivated_with_parent', $this->deactivatedWithParent, $withParent);
        $this->isActive = false;
        $this->deactivatedWithParent = $withParent;
    }

    public function activate(): void
    {
        $this->changes->record('is_active', $this->isActive, true);
        $this->changes->record('deactivated_with_parent', $this->deactivatedWithParent, false);
        $this->isActive = true;
        $this->deactivatedWithParent = false;
    }

    public function dropImage(): void
    {
        $this->changes->record('image_media_id', $this->imageMediaId, null);
        $this->imageMediaId = null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function name(): LocalizedName
    {
        return $this->name;
    }

    public function slugs(): Slugs
    {
        return $this->slugs;
    }

    public function imageMediaId(): ?string
    {
        return $this->imageMediaId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivatedWithParent(): bool
    {
        return $this->deactivatedWithParent;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'parent_id' => $this->parentId,
            'name_ar' => $this->name->ar,
            'name_en' => $this->name->en,
            'slug_ar' => $this->slugs->ar->value,
            'slug_en' => $this->slugs->en->value,
            'image_media_id' => $this->imageMediaId,
            'description_ar' => $this->descriptionAr,
            'description_en' => $this->descriptionEn,
            'is_active' => $this->isActive,
            'deactivated_with_parent' => $this->deactivatedWithParent,
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
