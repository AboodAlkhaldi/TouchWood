<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;

/**
 * Schedule stopped part way (content.md §1.7 #15; review of #382): **the store that refused, and the stores scheduled
 * already**, which stay scheduled - the rest stay drafts, for another press. The store's own refusal (Pricing's
 * ceiling naming its products, a notice no longer a draft...) is kept as `refusal` (and as the previous exception), so
 * the page says it in its own words beside this one. Its category is the refusal's.
 */
final class CampaignPartlyScheduled extends ContentError
{
    /**
     * @param  string  $store  the store that refused, by name
     * @param  string  $scheduled  the stores scheduled already, by name, as the reader's language lists them
     */
    public function __construct(
        public readonly string $store,
        public readonly string $scheduled,
        public readonly DomainError $refusal,
    ) {
        parent::__construct("Not scheduled in {$store}; scheduled already in {$scheduled}.", 0, $refusal);
    }

    public function type(): string
    {
        return 'content.campaign_partly_scheduled';
    }

    public function category(): ErrorCategory
    {
        return $this->refusal->category();
    }

    public function context(): array
    {
        return ['store' => $this->store, 'scheduled' => $this->scheduled, 'refusal' => $this->refusal->type()];
    }
}
