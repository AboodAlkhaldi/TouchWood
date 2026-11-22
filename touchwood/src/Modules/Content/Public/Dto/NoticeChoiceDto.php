<?php

declare(strict_types=1);

namespace Modules\Content\Public\Dto;

/**
 * An offer notice as a campaign's page shows it (`CampaignNotices`; content.md §2.1): its id, its title in both
 * languages, and its status in Ops' words - `DRAFT`, `SCHEDULED`, `SENDING`, `SENT`, `CANCELLED` or `FAILED`.
 */
final readonly class NoticeChoiceDto
{
    public const string DRAFT = 'DRAFT';

    public function __construct(
        public string $id,
        public string $titleAr,
        public string $titleEn,
        public string $status,
    ) {}

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
