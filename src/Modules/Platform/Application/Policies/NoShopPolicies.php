<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Policies;

use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Dto\ShopPolicyDto;
use Modules\Platform\Public\Enums\PolicyKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * No policies: Platform's own answer until the module that keeps them binds `ShopPolicies` (Content does,
 * flags-2026-11.md S3), as `UnnamedStaff` stands in for Access's names.
 */
final class NoShopPolicies implements ShopPolicies
{
    public function published(StoreId $store, PolicyKind $kind, string $locale): ?ShopPolicyDto
    {
        return null;
    }

    public function allPublished(StoreId $store, string $locale): array
    {
        return [];
    }
}
