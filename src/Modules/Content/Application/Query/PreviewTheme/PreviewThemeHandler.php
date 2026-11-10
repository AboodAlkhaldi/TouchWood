<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\PreviewTheme;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Repository\Themes;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * **Preview Theme** (content.md W5b), a Super Admin's: which theme the presser's browser may see on the shop, and
 * the store it opens on (the first of its stores). Under `content.theme.manage` in each of its stores; an unknown
 * theme is not found. The signed cookie itself is the panel's (`ThemePreviewCookie`); the shop draws it (PR 9).
 */
final readonly class PreviewThemeHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Themes $themes,
    ) {}

    /**
     * @return array{themeId: string, slug: string, store: StoreDto}
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(string $themeId): array
    {
        $this->access->holds(self::PERMISSION);
        $theme = $this->themes->find($themeId);
        $first = null;

        foreach ($theme === null ? [null] : array_keys($theme->stores) as $storeId) {
            $store = $this->access->authorize(self::PERMISSION, $storeId, item: true);
            $first ??= $store;
        }

        if ($theme === null || $first === null) {
            throw new ContentNotFound;
        }

        return ['themeId' => $theme->id, 'slug' => $theme->slug, 'store' => $first];
    }
}
