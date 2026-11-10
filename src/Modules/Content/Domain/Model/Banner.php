<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;
use Modules\Content\Domain\ValueObject\Schedule;

/**
 * A banner (content.md §1.6): its placement, the staff's name for it, a listing banner's category (none: every
 * list), its link, whether its artwork has words (then one image per language), its description per language, its
 * dates and its place in the order. `images` is `{DESKTOP|PHONE}:{any|ar|en}` => media id.
 */
final readonly class Banner
{
    /**
     * @param  array<string, string>  $images
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public BannerPlacement $placement,
        public ?string $categoryId,
        public string $name,
        public ?string $link,
        public bool $hasWords,
        public ?string $altAr,
        public ?string $altEn,
        public Schedule $schedule,
        public int $position,
        public array $images,
        public int $version,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        $images = $this->images;
        ksort($images);

        return [
            'placement' => $this->placement->value,
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'link' => $this->link,
            'has_words' => $this->hasWords,
            'alt_ar' => $this->altAr,
            'alt_en' => $this->altEn,
            'is_scheduled' => $this->schedule->isScheduled,
            'starts_at' => $this->schedule->startsAt?->format(DATE_ATOM),
            'ends_at' => $this->schedule->endsAt?->format(DATE_ATOM),
            'images' => http_build_query($images, '', ','),
        ];
    }
}
