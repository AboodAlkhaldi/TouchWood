<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Model\ThemePart;

/**
 * Themes (content.md §1.8, §5: `content.themes`, `content.theme_stores`, `content.theme_parts`,
 * `content.theme_part_images`).
 */
interface Themes
{
    public function find(string $id): ?Theme;

    public function lock(string $id): ?Theme;

    public function slugTaken(string $slug): bool;

    /** Writes the theme and its stores' moments (replacing them). */
    public function add(Theme $theme): void;

    public function update(Theme $theme): void;

    public function delete(string $id): void;

    /**
     * The store's scheduled themes, the ended included: what a new schedule may clash with.
     *
     * @return list<Theme>
     */
    public function scheduledIn(string $storeId): array;

    /**
     * The theme's filled parts, in §1.8's order.
     *
     * @return list<ThemePart>
     */
    public function partsOf(string $themeId): array;

    /**
     * Replaces the theme's parts with these: a part left out is emptied.
     *
     * @param  list<ThemePart>  $parts
     */
    public function replaceParts(string $themeId, array $parts): void;

    /** Whether a campaign links to the theme (a theme is deleted only unlinked, §5). */
    public function isLinked(string $themeId): bool;
}
