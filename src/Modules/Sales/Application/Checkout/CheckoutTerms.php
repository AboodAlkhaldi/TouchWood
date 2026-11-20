<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use DateTimeImmutable;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Dto\ShopPolicyDto;
use Modules\Platform\Public\Enums\PolicyKind;
use Modules\Sales\Domain\Exception\TermsChanged;
use Modules\Sales\Domain\Exception\TermsMissing;
use Modules\Sales\Domain\Exception\TermsNotAccepted;
use Modules\Sales\Domain\Model\AcceptedTerms;
use Shared\Domain\ValueObject\StoreId;

/**
 * The terms a checkout ticks (flags-2026-11.md S3.3), from Platform's `ShopPolicies` alone - Sales never asks Content
 * (owner, 2026-11-20): the store's published Terms and Conditions, and its Returns and Refunds policy when it has one.
 * **A store without published terms takes no orders** (`TermsMissing`); an order placed or paid without the box
 * ticked is refused (`TermsNotAccepted`) whatever the page sent, and so is one whose page showed other terms than the
 * store's now (`TermsChanged`). Both ways to order ask it: placing (`PlaceOrder`) and Pay (`PayOnline`), whose try
 * keeps what was ticked for the order it makes once paid.
 *
 * One read of the store's policies (`allPublished`, Content's cache), not one a kind, before the order's transaction.
 */
final readonly class CheckoutTerms
{
    public function __construct(
        private ShopPolicies $policies,
    ) {}

    /**
     * The Terms and Conditions, and the Returns and Refunds policy, the store has published now, in the language asked.
     *
     * @return array{terms: ?ShopPolicyDto, returns: ?ShopPolicyDto}
     */
    public function current(StoreId $store, string $locale): array
    {
        $found = ['terms' => null, 'returns' => null];

        foreach ($this->policies->allPublished($store, $locale) as $policy) {
            if ($policy->kind === PolicyKind::Terms) {
                $found['terms'] ??= $policy;
            } elseif ($policy->kind === PolicyKind::Returns) {
                $found['returns'] ??= $policy;
            }
        }

        return $found;
    }

    /** @throws TermsNotAccepted */
    public static function ticked(bool $agreed): void
    {
        if (! $agreed) {
            throw new TermsNotAccepted;
        }
    }

    /**
     * What the customer accepts: the store's terms as read before the order's transaction (`current`), which must be
     * the ones the page showed - the same policies at the same versions - else `TermsChanged`, the customer asked to
     * read them and tick again (owner, 2026-11-20: "stop and re-tick"). Pure: no read here, so it may run inside a
     * transaction without holding its locks through a cache refill.
     *
     * @param  array{terms: ?ShopPolicyDto, returns: ?ShopPolicyDto}  $current
     *
     * @throws TermsMissing|TermsChanged
     */
    public static function accept(array $current, ShownTerms $shown, DateTimeImmutable $at): AcceptedTerms
    {
        ['terms' => $terms, 'returns' => $returns] = $current;

        if ($terms === null) {
            throw new TermsMissing;
        }

        if (! $shown->matches($terms, $returns)) {
            throw new TermsChanged;
        }

        return new AcceptedTerms($terms->policyId, $terms->version, $at, $returns?->policyId, $returns?->version);
    }
}
