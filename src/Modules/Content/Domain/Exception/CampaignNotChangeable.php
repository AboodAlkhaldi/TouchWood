<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change a run's state forbids (content.md §1.7 #8, #12, §7), naming the rule it broke.
 */
final class CampaignNotChangeable extends ContentError
{
    /** Cancel lost to going live (§1.7 #9): "This campaign has just gone live. End it instead." */
    public const string WENT_LIVE = 'went_live';

    /** End on a run that is not live. */
    public const string NOT_LIVE = 'not_live';

    /** Cancel or Schedule on a run that is not where that step starts (scheduled, a draft). */
    public const string NOT_SCHEDULED = 'not_scheduled';

    /** Prices of a run that has started (W4: "Prices are fixed while a campaign runs. End it, or change its end."). */
    public const string PRICES_FIXED = 'prices_fixed';

    /** Half live (§1.7 #8): once any run is live, the start, the stores and the theme link are locked. */
    public const string LOCKED = 'locked';

    /** Every run ended or cancelled: history, never revived. */
    public const string OVER = 'over';

    /** A store whose run is scheduled taken off the campaign: cancel that store's run first. */
    public const string STORE_SCHEDULED = 'store_scheduled';

    /** A notice of a run that has gone live: sent, it can't be recalled (§1.7 #10). */
    public const string NOTICE_SENT = 'notice_sent';

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
