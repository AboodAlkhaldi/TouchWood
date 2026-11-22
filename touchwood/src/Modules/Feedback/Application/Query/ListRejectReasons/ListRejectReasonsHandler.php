<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListRejectReasons;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Domain\Model\RejectReason;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The ready reasons in their order (feedback.md §2.4 B4) — for the reject and unpublish dialogs, so for
 * anyone who moderates reviews or answers questions in some store, and for the admins who edit them.
 */
final readonly class ListRejectReasonsHandler
{
    /** The jobs that read the list: moderating reviews, answering questions, or editing it. */
    public const array PERMISSIONS = [FeedbackPermissions::REVIEW_MODERATE, FeedbackPermissions::QUESTION_ANSWER, FeedbackPermissions::SETTINGS_UPDATE];

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private RejectReasons $reasons,
    ) {}

    /**
     * @return list<RejectReasonRow>
     *
     * @throws Unauthorized
     */
    public function handle(ListRejectReasons $query): array
    {
        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized(self::PERMISSIONS[0]);
        }

        foreach (self::PERMISSIONS as $permission) {
            $stores = $this->authorizer->storesWith($permission);

            if ($stores !== []) {
                // Asked where it is held: every store, or the first of its stores.
                $this->authorizer->authorize($permission, $stores === null ? PermissionScope::allStores() : PermissionScope::store($stores[0]));

                return array_map(static fn (RejectReason $reason): RejectReasonRow => new RejectReasonRow($reason->id, $reason->textAr, $reason->textEn, $reason->position), $this->reasons->all());
            }
        }

        throw new Unauthorized(self::PERMISSIONS[0]);
    }
}
