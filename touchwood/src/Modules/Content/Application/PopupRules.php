<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use DateTimeImmutable;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\PopupClash;
use Modules\Content\Domain\Model\Popup;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * What a pop-up may be once scheduled (content.md §1.10, §1.1 rules 2 and 10): English beside every Arabic, its image
 * ready, an end not already past - and **no other pop-up of the store sharing a moment with it**, checked under the
 * store's pop-up lock (§1.1 rule 12), so of two schedules racing one wins and the other is told (`PopupClash`).
 */
final readonly class PopupRules
{
    public function __construct(
        private Popups $popups,
        private ContentLocks $locks,
        private ContentImages $images,
    ) {}

    /** Call first in the transaction, before any pop-up row is locked (one lock order). */
    public function lock(string $storeId): void
    {
        $this->locks->lock($storeId, ContentLocks::POPUPS);
    }

    /**
     * @throws ContentError
     */
    public function requireSchedulable(Popup $popup, StoreDto $store, DateTimeImmutable $now): void
    {
        Words::requireEnglish('title', $popup->titleAr, $popup->titleEn);
        Words::requireEnglish('text', $popup->textAr, $popup->textEn);
        Words::requireEnglish('button', $popup->buttonAr, $popup->buttonEn);

        if ($popup->schedule->endsAt !== null && $popup->schedule->endsAt <= $now) {
            throw new InvalidContentInput('ends_at', InvalidContentInput::PAST);
        }

        $this->images->requireReady(['image' => $popup->mediaId]);

        foreach ($this->popups->scheduledIn($store->id) as $other) {
            if ($other->id !== $popup->id && $other->schedule->overlaps($popup->schedule)) {
                throw new PopupClash(
                    $other->name,
                    $store->name->in(app()->getLocale()),
                    $other->schedule->startsAt === null ? '-' : Moments::shown($other->schedule->startsAt, $store),
                    $other->schedule->endsAt === null ? '-' : Moments::shown($other->schedule->endsAt, $store),
                );
            }
        }
    }
}
