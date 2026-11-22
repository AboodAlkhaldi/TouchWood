<?php

declare(strict_types=1);

namespace Modules\Content\Public\Contracts;

use Modules\Content\Public\Dto\NoticeChoiceDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * The offer notices a campaign's page may name (content.md §1.7 #10, §2.1; ops.md §9.7 #1). **Declared by Content,
 * bound by Ops**, as Access's `SecurityMessages` is, so nothing depends on Ops (handoff §4.4); Content's own default
 * answers "none". Module to module: no permission is checked here - the calling use case checks its own.
 */
interface CampaignNotices
{
    /**
     * The store's draft notices, newest first, after the one named; at most `$limit` (1 to 100).
     *
     * @return list<NoticeChoiceDto>
     */
    public function drafts(StoreId $store, ?string $after, int $limit): array;

    /**
     * These notices of the store, whatever their status (a draft, since sent, cancelled...), in no set order. One
     * deleted, or of another store, is left out: it reads as gone.
     *
     * @param  list<string>  $ids
     * @return list<NoticeChoiceDto>
     */
    public function named(StoreId $store, array $ids): array;
}
