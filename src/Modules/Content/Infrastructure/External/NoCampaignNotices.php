<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\External;

use Modules\Content\Public\Contracts\CampaignNotices;
use Shared\Domain\ValueObject\StoreId;

/**
 * `CampaignNotices` with no Ops bound (content.md §2.1): no notice to offer, none named. Ops' provider replaces it, as
 * it replaces Access's `SecurityMessages`.
 */
final readonly class NoCampaignNotices implements CampaignNotices
{
    public function drafts(StoreId $store, ?string $after, int $limit): array
    {
        return [];
    }

    public function named(StoreId $store, array $ids): array
    {
        return [];
    }
}
