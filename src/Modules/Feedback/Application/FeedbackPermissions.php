<?php

declare(strict_types=1);

namespace Modules\Feedback\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Feedback's permissions (feedback.md §3): every customer writes reviews, asks questions and keeps a
 * wishlist; staff moderate reviews and answer questions under Support (owner, 2026-10-18), and see
 * wishlists under Customers ("Customers › Favourited products", handoff §14); only an admin switches
 * ratings in a store's lists.
 */
final class FeedbackPermissions
{
    /** Write a review of a product delivered to me (one per product). */
    public const string REVIEW_WRITE = 'feedback.review.write';

    /** Ask a question about a product in a store. */
    public const string QUESTION_ASK = 'feedback.question.ask';

    /** Save a product to my wishlist, remove it. */
    public const string WISHLIST_MANAGE = 'feedback.wishlist.manage';

    /** Approve, reject or unpublish the reviews written in a store. */
    public const string REVIEW_MODERATE = 'feedback.review.moderate';

    /** Answer, correct, reject or unpublish the questions asked in a store. */
    public const string QUESTION_ANSWER = 'feedback.question.answer';

    /** A customer's wishlist items of a store, and the store's "Favourited products". */
    public const string WISHLIST_VIEW = 'feedback.wishlist.view';

    /** Switch ratings in a store's lists — admin-only (owner, 2026-10-09). */
    public const string SETTINGS_UPDATE = 'feedback.settings.update';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $customer = static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::EveryCustomer, kind: PermissionKind::Global);

        return [
            $customer(self::REVIEW_WRITE),
            $customer(self::QUESTION_ASK),
            $customer(self::WISHLIST_MANAGE),
            new PermissionDefinitionDto(self::REVIEW_MODERATE, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Support),
            new PermissionDefinitionDto(self::QUESTION_ANSWER, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Support),
            new PermissionDefinitionDto(self::WISHLIST_VIEW, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Customers),
            new PermissionDefinitionDto(self::SETTINGS_UPDATE, PermissionAudience::Role, adminOnly: true, kind: PermissionKind::PerStore, group: PermissionGroup::Support),
        ];
    }
}
