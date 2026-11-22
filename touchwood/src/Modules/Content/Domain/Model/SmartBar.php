<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A smart bar (content.md §1.9): a short message and a link above one category's lists (with optional dates) or above
 * every list of a campaign run's store while the run is live.
 */
final readonly class SmartBar
{
    public function __construct(
        public string $id,
        public string $storeId,
        public SmartBarScope $scope,
        public ?string $categoryId,
        public ?string $runId,
        public string $messageAr,
        public string $messageEn,
        public ?string $link,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
    ) {}

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return [
            'scope' => $this->scope->value,
            'category_id' => $this->categoryId,
            'run_id' => $this->runId,
            'message_ar' => $this->messageAr,
            'message_en' => $this->messageEn,
            'link' => $this->link,
            'starts_at' => $this->startsAt?->format(DATE_ATOM),
            'ends_at' => $this->endsAt?->format(DATE_ATOM),
        ];
    }
}
