<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SchedulePopup;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\PopupRules;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Popup;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Schedules a draft pop-up (content.md §1.10): English beside every Arabic, its image ready, an end not past, and
 * **no other pop-up of the store at any moment of it** - refused, naming the other and its dates (`PopupClash`),
 * under the store's pop-up lock so of two racing schedules one wins.
 */
final readonly class SchedulePopupHandler
{
    public const string PERMISSION = ContentPermissions::POPUP_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Popups $popups,
        private PopupRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(SchedulePopup $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->popups->find($command->popupId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $store, $staffId): void {
            $this->rules->lock($store->id);
            $was = $this->popups->lock($command->popupId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $store);

            if ($was->schedule->isScheduled) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $now = Moments::now();
            $popup = new Popup($was->id, $was->storeId, $was->name, $was->titleAr, $was->titleEn, $was->textAr, $was->textEn, $was->buttonAr, $was->buttonEn, $was->link, $was->delaySeconds, $was->mediaId, new Schedule(true, $was->schedule->startsAt ?? $now, $was->schedule->endsAt), $was->version + 1, $staffId, $now);
            $this->rules->requireSchedulable($popup, $store, $now);
            $this->marks->showing(UnreadTranslations::POPUP, $was->id, $command->read);
            $this->popups->update($popup);
            $this->platform->recordAudit(ContentAudit::entry('content.popup.scheduled', 'content.popup', $was->id, $store->id, ContentAudit::diff($was->fields(), $popup->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
