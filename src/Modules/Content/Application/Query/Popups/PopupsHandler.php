<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Popups;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\Popups;
use Shared\Application\Unauthorized;

/**
 * What W6 shows (content.md §4.4): the store's pop-ups (name, state, start, end), 50 at a time, and one pop-up's form.
 * Under `content.popup.manage` in the store.
 */
final readonly class PopupsHandler
{
    public const string PERMISSION = ContentPermissions::POPUP_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Popups $popups,
        private ScreenReads $reads,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ContentError|Unauthorized
     */
    public function list(?string $storeId, ?string $after = null): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);

        return $this->reads->popups($store->id, $after);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function popup(string $popupId): array
    {
        $this->access->holds(self::PERMISSION);
        $popup = $this->popups->find($popupId);
        $this->access->authorize(self::PERMISSION, $popup?->storeId, item: true);

        return $popup === null ? [] : ['id' => $popup->id, 'store_id' => $popup->storeId, ...$popup->fields(), 'version' => $popup->version];
    }
}
