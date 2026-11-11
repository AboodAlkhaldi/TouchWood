<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Content\Domain\Exception\TranslationUnread;
use Modules\Content\Domain\Repository\UnreadTranslations;

/**
 * Translate's mark on an item, kept until someone says they read it (content.md §1.3, the owner's P14 of 2026-11-11):
 * English Translate filled and nobody ticked "I read the translated English." stays marked across sessions, and
 * Publish, Schedule, Show or switching the bar on asks for the tick again. Only the tick clears it: typing over the
 * words does not (the owner's "the tick clears the mark").
 *
 * The form says whether a box it sends still carries Translate's mark (`translated`) and whether the tick was given
 * (`read`). Each handler calls this inside its own transaction, so a refusal undoes the save. An item shoppers see now
 * or are due to see - published, scheduled, live, shown, switched on - never keeps an unread mark: its save is refused
 * instead, as its Publish would be.
 */
final readonly class TranslationMarks
{
    public function __construct(
        private UnreadTranslations $marks,
        private ContentAccess $access,
    ) {}

    /**
     * After an item is saved.
     *
     * @param  bool  $shown  whether shoppers see it now, or will without another step
     *
     * @throws TranslationUnread
     */
    public function saved(string $kind, string $itemId, string $storeId, bool $translated, bool $read, bool $shown): void
    {
        if ($read) {
            $this->marks->clear($kind, $itemId);

            return;
        }

        if (! $translated && ! $this->marks->has($kind, $itemId)) {
            return;
        }

        if ($shown) {
            throw new TranslationUnread;
        }

        if ($translated) {
            $this->marks->mark($kind, $itemId, $storeId, $this->access->staffId(), Moments::now());
        }
    }

    /**
     * Before an item reaches shoppers: Publish, Schedule, Show, the bar switched on.
     *
     * @throws TranslationUnread
     */
    public function showing(string $kind, string $itemId, bool $read): void
    {
        if ($read) {
            $this->marks->clear($kind, $itemId);

            return;
        }

        if ($this->marks->has($kind, $itemId)) {
            throw new TranslationUnread;
        }
    }

    /** An item deleted takes its mark with it. */
    public function forget(string $kind, string $itemId): void
    {
        $this->marks->clear($kind, $itemId);
    }
}
