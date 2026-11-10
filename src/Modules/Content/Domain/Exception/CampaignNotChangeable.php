<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change a run's state forbids (content.md §1.7 #8, #12, §7), naming the rule it broke.
 */
final class CampaignNotChangeable extends ContentError
{
    /**
     * @param  string  $rule  the rule, as its words are keyed
     */
    public function __construct(
        public readonly string $rule,
    ) {
        parent::__construct("The campaign cannot change that now ({$rule}).");
    }

    public function type(): string
    {
        return 'content.campaign_not_changeable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['rule' => $this->rule];
    }
}
