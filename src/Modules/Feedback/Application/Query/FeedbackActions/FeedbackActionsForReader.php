<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\FeedbackActions;

use Modules\Feedback\Application\FeedbackPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Which of Feedback's screen buttons the person looking may be offered in a store (feedback.md §4.1):
 * asked here because a screen may not ask the authorizer itself (tests/Architecture/AccessDecisionsTest).
 * Offering is never allowing - every handler behind a button asks again (handoff §19).
 */
final readonly class FeedbackActionsForReader
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    public function forStore(StoreId $store): FeedbackActions
    {
        return new FeedbackActions(
            $this->holds(FeedbackPermissions::REVIEW_MODERATE, $store),
            $this->holds(FeedbackPermissions::QUESTION_ANSWER, $store),
            $this->holds(FeedbackPermissions::WISHLIST_VIEW, $store),
            $this->holds(FeedbackPermissions::SETTINGS_UPDATE, $store),
        );
    }

    private function holds(string $permission, StoreId $store): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $held) {
            if ($held->equals($store)) {
                return true;
            }
        }

        return false;
    }
}
