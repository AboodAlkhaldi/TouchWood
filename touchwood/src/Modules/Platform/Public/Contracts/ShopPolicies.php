<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\ShopPolicyDto;
use Modules\Platform\Public\Enums\PolicyKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's published policies, for the modules that need them but may not ask Content (flags-2026-11.md S3; owner,
 * 2026-11-20: "a Platform contract"): Sales - the terms a checkout ticks and a store without terms taking no orders
 * (S3.3); Access - sign-up's links and the terms version a customer accepts (S3.4); B2B - a company application's
 * terms (S3.6).
 *
 * Content keeps the policies and binds this. Until it does, no store has any (Platform's own `NoShopPolicies`).
 */
interface ShopPolicies
{
    /**
     * The store's published policy of this kind, in the language asked (`ar` or `en`); null when it has none. A store
     * has at most one published policy of each kind but Other (S3.1); asked for Other, the first in the store's order.
     */
    public function published(StoreId $store, PolicyKind $kind, string $locale): ?ShopPolicyDto;

    /**
     * Every policy the store has published, in the kinds' order (S3.5, F19), in the language asked.
     *
     * @return list<ShopPolicyDto>
     */
    public function allPublished(StoreId $store, string $locale): array;

    /**
     * Where policies' kept versions read in a store's shop, in the language asked (the owner's decisions of 2026-11-20:
     * a text no longer valid stays readable, labelled as old): each version that was ever in force in the store, by
     * `"{policyId}:{version}"`, the address it was read at there last. Its page is `/{store}/{locale}/policies/{slug}`
     * with `?version={version}&policy={policyId}`, which opens even after the policy is switched off there. A version
     * never in force in the store is left out.
     *
     * @param  list<array{0: string, 1: int}>  $versions  each a policy's id and a version of its text
     * @return array<string, string>
     */
    public function versionAddresses(StoreId $store, array $versions, string $locale): array;
}
