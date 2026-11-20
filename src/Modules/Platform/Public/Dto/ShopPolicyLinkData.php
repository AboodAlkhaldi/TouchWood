<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **A store's published policy as a page names it** beside a tick (flags-2026-11.md S3.3, S3.4, S3.6): checkout's,
 * sign-up's, a company application's. Which policy and which version of its text the page shows - sent back with the
 * tick, so the server refuses when what is live now is another (owner, 2026-11-20: "stop and re-tick") - and its
 * page's address, opened in a new tab. The module asking `ShopPolicies` writes the address; Platform's, as the
 * contract's shape every such page reads, as `MoneyData` is.
 */
#[TypeScript]
final readonly class ShopPolicyLinkData
{
    public function __construct(
        public string $policyId,
        public int $version,
        public string $url,
    ) {}

    public static function of(ShopPolicyDto $policy, string $url): self
    {
        return new self($policy->policyId, $policy->version, $url);
    }
}
