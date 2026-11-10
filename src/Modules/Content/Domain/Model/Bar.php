<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A store's announcement bar (content.md §1.5): one line per language, an optional link, on or off with an optional
 * start and end. Every store has one; never saved, it is off and empty (`Bar::none`).
 */
final readonly class Bar
{
    public function __construct(
        public string $storeId,
        public ?string $textAr,
        public ?string $textEn,
        public ?string $link,
        public bool $isOn,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public int $version,
        public ?string $updatedBy,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public static function none(string $storeId): self
    {
        return new self($storeId, null, null, null, false, null, null, 0, null, null);
    }

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return [
            'text_ar' => $this->textAr,
            'text_en' => $this->textEn,
            'link' => $this->link,
            'is_on' => $this->isOn,
            'starts_at' => $this->startsAt?->format(DATE_ATOM),
            'ends_at' => $this->endsAt?->format(DATE_ATOM),
        ];
    }
}
