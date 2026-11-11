<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Throwable;

/**
 * Schedule stopped part way (content.md §1.7 #15; reviews of #382): **the store that refused, why, and every store whose
 * run is scheduled now** - those stay scheduled, the rest stay drafts, for another press once the page is opened again
 * (the campaign's version moved).
 *
 * **The store's own refusal is carried, not hidden**: its sentence is `reason`, in the person's language, and its
 * context is merged into this one's - Pricing's `variants` among it - so a form still marks the rows it names
 * (`FormErrors::rows`). Its `rule` is kept as `refusal_rule`, this error having no rules of its own. A failure that is
 * no refusal (the database's retries spent) is carried the same way, with words saying so. The category is the
 * refusal's, or Unavailable for such a failure.
 */
final class CampaignPartlyScheduled extends ContentError
{
    /**
     * @param  string  $store  the store that refused, by name
     * @param  string  $scheduled  the stores scheduled now, by name, as the reader's language lists them
     * @param  string  $reason  the refusal's own sentence, or the words for a failure that is none
     */
    public function __construct(
        public readonly string $store,
        public readonly string $scheduled,
        public readonly string $reason,
        public readonly ?DomainError $refusal,
        ?Throwable $failure = null,
        /** The refused store's id: which store's prices the sizes it names are (W4b marks them in that tab only). */
        public readonly ?string $storeId = null,
    ) {
        parent::__construct("Not scheduled in {$store}; scheduled already in {$scheduled}.", 0, $failure ?? $refusal);
    }

    public function type(): string
    {
        return 'content.campaign_partly_scheduled';
    }

    public function category(): ErrorCategory
    {
        return $this->refusal?->category() ?? ErrorCategory::Unavailable;
    }

    public function context(): array
    {
        $carried = $this->refusal?->context() ?? [];
        $rule = $carried['rule'] ?? null;
        unset($carried['rule']);

        return [
            ...$carried,
            'refusal_rule' => $rule,
            'store' => $this->store,
            'scheduled' => $this->scheduled,
            'reason' => $this->reason,
            'refusal' => $this->refusal?->type() ?? 'unexpected',
        ];
    }
}
