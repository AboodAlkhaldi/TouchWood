<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePopup;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\PopupRules;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/** Deletes a draft pop-up (content.md §1.1 rule 11); a scheduled, live or ended one is refused (`NotDeletable`). */
final readonly class DeletePopupHandler
{
    public const string PERMISSION = ContentPermissions::POPUP_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Popups $popups,
        private PopupRules $rules,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeletePopup $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->popups->find($command->popupId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);

        $this->db->transaction(function () use ($command, $store): void {
            $this->rules->lock($store->id);
            $was = $this->popups->lock($command->popupId) ?? throw new ContentNotFound;

            if ($was->schedule->isScheduled) {
                throw new NotDeletable;
            }

            $this->popups->delete($was->id);
            $this->marks->forget(UnreadTranslations::POPUP, $was->id);
            $this->platform->recordAudit(ContentAudit::entry('content.popup.deleted', 'content.popup', $was->id, $store->id, ContentAudit::diff($was->fields(), [])));
            $this->cache->invalidate();
        }, 3);
    }
}
