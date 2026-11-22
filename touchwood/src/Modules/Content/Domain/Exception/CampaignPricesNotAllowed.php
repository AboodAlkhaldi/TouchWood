<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Scheduling a run with price choices without Pricing's permission in its store (content.md §1.7 #16, §7),
 * naming the store.
 */
final class CampaignPricesNotAllowed extends ContentError
{
    /**
     * @param  string  $store  the store's name
     */
    public function __construct(
        public readonly string $store,
    ) {
        parent::__construct("Changing prices in {$store} needs Pricing's permission there.");
    }

    public function type(): string
    {
        return 'content.campaign_prices_not_allowed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }

    public function context(): array
    {
        return ['store' => $this->store];
    }
}
