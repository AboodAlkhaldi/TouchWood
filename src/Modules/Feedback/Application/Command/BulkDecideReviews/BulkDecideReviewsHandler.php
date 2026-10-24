<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\BulkDecideReviews;

use Modules\Feedback\Application\Command\ApproveReview\ApproveReview;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReviewHandler;
use Modules\Feedback\Application\Command\RejectReview\RejectReview;
use Modules\Feedback\Application\Command\RejectReview\RejectReviewHandler;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReview;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReviewHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Moderation\BulkDecision;
use Modules\Feedback\Application\Moderation\BulkInputs;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\ReviewNotFound;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Feedback\Domain\Repository\Reviews;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A decision on many reviews at once** (feedback.md §2.4 B2; owner, Q4 (b)): approve, reject, unpublish up to a page (50)
 * of one store's reviews, one optional reason for all. **Each is decided on its own** — the one-review
 * command, its own lock and transaction, audited as ever — so one decided meanwhile by someone else is
 * skipped and named, not a failure of the rest; one of another store, or unknown, is named not found.
 */
final readonly class BulkDecideReviewsHandler
{
    public const string PERMISSION = FeedbackPermissions::REVIEW_MODERATE;

    /** What may be done to many at once. */
    public const array ACTIONS = ['approve', 'reject', 'unpublish'];

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Reviews $reviews,
        private ApproveReviewHandler $approve,
        private RejectReviewHandler $reject,
        private UnpublishReviewHandler $unpublish,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(BulkDecideReviews $command): BulkDecision
    {
        $action = BulkInputs::action($command->action, self::ACTIONS);
        $ids = BulkInputs::ids($command->reviewIds);
        $reason = $action === 'approve' ? null : FeedbackText::reason($command->reason);
        $store = StoreId::fromString(BulkInputs::store($command->storeId));
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized(self::PERMISSION);
        }

        [$done, $decided, $missing] = [[], [], []];

        foreach ($ids as $id) {
            $found = $this->reviews->storeOf($id);

            if ($found === null || ! $found->equals($store)) {
                $missing[] = $id;

                continue;
            }

            try {
                match ($action) {
                    'approve' => $this->approve->handle(new ApproveReview($id)),
                    'reject' => $this->reject->handle(new RejectReview($id, $reason)),
                    'unpublish' => $this->unpublish->handle(new UnpublishReview($id, $reason)),
                    default => throw new InvalidFeedbackAttribute('action', implode(', ', self::ACTIONS)),
                };
                $done[] = $id;
            } catch (InvalidModeration) {
                $decided[] = $id;
            } catch (ReviewNotFound) {
                $missing[] = $id;
            }
        }

        return new BulkDecision($done, $decided, $missing);
    }
}
