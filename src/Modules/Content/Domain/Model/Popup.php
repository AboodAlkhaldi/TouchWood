<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;
use Modules\Content\Domain\ValueObject\Schedule;

/**
 * A pop-up (content.md §1.10): the staff's name for it, title and text per language, an optional image, button words
 * per language with their link, the delay before it opens, its dates. One live at a time per store.
 */
final readonly class Popup
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $name,
        public string $titleAr,
        public ?string $titleEn,
        public string $textAr,
        public ?string $textEn,
        public ?string $buttonAr,
        public ?string $buttonEn,
        public ?string $link,
        public int $delaySeconds,
        public ?string $mediaId,
        public Schedule $schedule,
        public int $version,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return [
            'name' => $this->name,
            'title_ar' => $this->titleAr,
            'title_en' => $this->titleEn,
            'text_ar' => $this->textAr,
            'text_en' => $this->textEn,
            'button_ar' => $this->buttonAr,
            'button_en' => $this->buttonEn,
            'link' => $this->link,
            'delay_seconds' => $this->delaySeconds,
            'media_id' => $this->mediaId,
            'is_scheduled' => $this->schedule->isScheduled,
            'starts_at' => $this->schedule->startsAt?->format(DATE_ATOM),
            'ends_at' => $this->schedule->endsAt?->format(DATE_ATOM),
        ];
    }
}
