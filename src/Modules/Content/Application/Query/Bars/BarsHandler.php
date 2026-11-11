<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Bars;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\FrameItems;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Shared\Application\Unauthorized;

/**
 * What W2 shows (content.md §4.4), both tabs under `content.bar.manage` in the store: the announcement bar's form,
 * and the smart bars' list (message, where shown, state, start, end). The theme's line over the bar joins with themes
 * (plan PR 6).
 */
final readonly class BarsHandler
{
    public const string PERMISSION = ContentPermissions::BAR_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private FrameItems $frame,
        private ScreenReads $reads,
        private UnreadTranslations $marks,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function bar(?string $storeId): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);
        $bar = $this->frame->bar($store->id);

        return [...$bar->fields(), 'version' => $bar->version, 'translation_unread' => $this->marks->has(UnreadTranslations::BAR, $store->id)];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ContentError|Unauthorized
     */
    public function smartBars(?string $storeId): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);

        return $this->reads->smartBars($store->id);
    }
}
