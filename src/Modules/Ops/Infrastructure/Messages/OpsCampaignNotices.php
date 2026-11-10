<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Dto\NoticeChoiceDto;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Repository\Notices;
use Shared\Domain\ValueObject\StoreId;

/**
 * Content's `CampaignNotices` port, answered by Ops (ops.md §9.7 #1; content.md §2.1): a store's draft notices for a
 * campaign's page to offer, and the notices it names, with their status now. One query each.
 */
final readonly class OpsCampaignNotices implements CampaignNotices
{
    public function __construct(
        private Notices $notices,
    ) {}

    public function drafts(StoreId $store, ?string $after, int $limit): array
    {
        return array_map(self::choice(...), $this->notices->drafts($store, $after, $limit));
    }

    public function named(StoreId $store, array $ids): array
    {
        return array_map(self::choice(...), $this->notices->named($store, $ids));
    }

    private static function choice(Notice $notice): NoticeChoiceDto
    {
        return new NoticeChoiceDto($notice->id, $notice->text->titleAr, $notice->text->titleEn, $notice->status->value);
    }
}
