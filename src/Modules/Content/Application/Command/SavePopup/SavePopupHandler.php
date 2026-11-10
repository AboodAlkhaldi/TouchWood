<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SavePopup;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentImages;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\PopupRules;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Popup;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * Writes a pop-up (content.md §1.10): name (staff only, 100), title (80) and text (240) per language, Arabic to save;
 * button words (40) with their link, together or not at all; a delay of 3 to 60 seconds (8); an optional public
 * image; its dates. A new one is a draft. **One already scheduled stays held to Schedule's rules**, the clash check
 * included, under the store's pop-up lock. Refused over a newer version.
 */
final readonly class SavePopupHandler
{
    public const string PERMISSION = ContentPermissions::POPUP_MANAGE;

    public const int MIN_DELAY = 3;

    public const int MAX_DELAY = 60;

    public const int DEFAULT_DELAY = 8;

    public function __construct(
        private ContentAccess $access,
        private Popups $popups,
        private PopupRules $rules,
        private ContentImages $images,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @return string the pop-up's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SavePopup $command): string
    {
        if ($command->popupId === null) {
            $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        } else {
            $this->access->holds(self::PERMISSION);
            $found = $this->popups->find($command->popupId);
            $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        }

        $staffId = $this->access->staffId();
        $fields = $this->fields($command, $store);

        return $this->db->transaction(function () use ($command, $store, $staffId, $fields): string {
            $now = Moments::now();
            $this->rules->lock($store->id);

            if ($command->popupId === null) {
                $id = strtolower((string) Str::ulid());
                $popup = $this->popup($id, $store->id, $fields, new Schedule(false, $fields['starts'], $fields['ends']), 1, $staffId, $now);
                $this->popups->add($popup);
                $this->platform->recordAudit(ContentAudit::entry('content.popup.created', 'content.popup', $id, $store->id, ContentAudit::diff([], $popup->fields())));
                $this->cache->invalidate();

                return $id;
            }

            $was = $this->popups->lock($command->popupId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $store);
            $starts = $fields['starts'] ?? ($was->schedule->isScheduled ? $now : null);
            $popup = $this->popup($was->id, $was->storeId, $fields, new Schedule($was->schedule->isScheduled, $starts, $fields['ends']), $was->version + 1, $staffId, $now);

            if ($was->schedule->isScheduled) {
                $this->rules->requireSchedulable($popup, $store, $now);
            }

            $this->popups->update($popup);
            $this->platform->recordAudit(ContentAudit::entry('content.popup.changed', 'content.popup', $was->id, $store->id, ContentAudit::diff($was->fields(), $popup->fields())));
            $this->cache->invalidate();

            return $was->id;
        });
    }

    /**
     * @param  array{name: string, title: array{ar: string, en: string|null}, text: array{ar: string, en: string|null}, button: array{ar: string|null, en: string|null}, link: string|null, delay: int, media: string|null, starts: DateTimeImmutable|null, ends: DateTimeImmutable|null}  $fields
     */
    private function popup(string $id, string $storeId, array $fields, Schedule $schedule, int $version, ?string $staffId, DateTimeImmutable $now): Popup
    {
        return new Popup($id, $storeId, $fields['name'], $fields['title']['ar'], $fields['title']['en'], $fields['text']['ar'], $fields['text']['en'], $fields['button']['ar'], $fields['button']['en'], $fields['link'], $fields['delay'], $fields['media'], $schedule, $version, $staffId, $now);
    }

    /**
     * @return array{name: string, title: array{ar: string, en: string|null}, text: array{ar: string, en: string|null}, button: array{ar: string|null, en: string|null}, link: string|null, delay: int, media: string|null, starts: DateTimeImmutable|null, ends: DateTimeImmutable|null}
     *
     * @throws ContentError
     */
    private function fields(SavePopup $command, StoreDto $store): array
    {
        $button = Words::pair('button', $command->buttonAr, $command->buttonEn, 40);
        $link = Words::link('link', $command->link);

        // Button words with nowhere to go, or a link no button opens, is half a button.
        if (($button['ar'] === null) !== ($link === null)) {
            throw new InvalidContentInput($button['ar'] === null ? 'button_ar' : 'link', InvalidContentInput::REQUIRED);
        }

        $delay = $command->delaySeconds ?? self::DEFAULT_DELAY;

        if ($delay < self::MIN_DELAY || $delay > self::MAX_DELAY) {
            throw new InvalidContentInput('delay_seconds', InvalidContentInput::RANGE, values: ['min' => self::MIN_DELAY, 'max' => self::MAX_DELAY]);
        }

        [$starts, $ends] = Moments::range($command->startsAt, $command->endsAt, $store);

        return [
            'name' => Words::required('name', $command->name, 100),
            'title' => Words::arabic('title', $command->titleAr, $command->titleEn, 80),
            'text' => Words::arabic('text', $command->textAr, $command->textEn, 240),
            'button' => $button,
            'link' => $link,
            'delay' => $delay,
            'media' => $this->images->files(['image' => $command->mediaId])['image'],
            'starts' => $starts,
            'ends' => $ends,
        ];
    }
}
