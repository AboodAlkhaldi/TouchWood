<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndPopup;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\PopupRules;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Popup;
use Modules\Content\Domain\Model\ScheduleState;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/** Ends a live pop-up now (content.md §4.1): its end becomes this moment. Only a live one. */
final readonly class EndPopupHandler
{
    public const string PERMISSION = ContentPermissions::POPUP_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Popups $popups,
        private PopupRules $rules,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(EndPopup $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->popups->find($command->popupId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $store, $staffId): void {
            $this->rules->lock($store->id);
            $was = $this->popups->lock($command->popupId) ?? throw new ContentNotFound;
            $now = Moments::now();

            if ($was->schedule->state($now) !== ScheduleState::Live) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            [$starts, $ends] = Moments::endNow($was->schedule->startsAt, $now);
            $popup = new Popup($was->id, $was->storeId, $was->name, $was->titleAr, $was->titleEn, $was->textAr, $was->textEn, $was->buttonAr, $was->buttonEn, $was->link, $was->delaySeconds, $was->mediaId, new Schedule(true, $starts, $ends), $was->version + 1, $staffId, $now);
            $this->popups->update($popup);
            $this->platform->recordAudit(ContentAudit::entry('content.popup.ended', 'content.popup', $was->id, $store->id, ContentAudit::diff($was->fields(), $popup->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
