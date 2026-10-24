<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\BulkDecideQuestions;

use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestion;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestionHandler;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestion;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestionHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Moderation\BulkDecision;
use Modules\Feedback\Application\Moderation\BulkInputs;
use Modules\Feedback\Application\StaffChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\QuestionNotFound;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Feedback\Domain\Repository\Questions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A decision on many questions at once** (feedback.md §2.4 B2; owner, Q4 (b)): reject, unpublish up to a page (50)
 * of one store's questions, one optional reason for all. **Each is decided on its own** — the one-question
 * command, its own lock and transaction, audited as ever — so one decided meanwhile by someone else is
 * skipped and named, not a failure of the rest; one of another store, or unknown, is named not found.
 */
final readonly class BulkDecideQuestionsHandler
{
    public const string PERMISSION = FeedbackPermissions::QUESTION_ANSWER;

    /** What may be done to many at once. */
    public const array ACTIONS = ['reject', 'unpublish'];

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private StaffChange $change,
        private Questions $questions,
        private RejectQuestionHandler $reject,
        private UnpublishQuestionHandler $unpublish,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(BulkDecideQuestions $command): BulkDecision
    {
        // The job in the store first — an off store only for those who switch stores — then what was sent.
        $store = StoreId::fromString(BulkInputs::store($command->storeId));
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->change->refuseOffStore(self::PERMISSION, $store);
        $action = BulkInputs::action($command->action, self::ACTIONS);
        $ids = BulkInputs::ids($command->questionIds);
        $reason = FeedbackText::reason($command->reason);

        [$done, $decided, $missing] = [[], [], []];

        foreach ($ids as $id) {
            $found = $this->questions->storeOf($id);

            if ($found === null || ! $found->equals($store)) {
                $missing[] = $id;

                continue;
            }

            try {
                match ($action) {
                    'reject' => $this->reject->handle(new RejectQuestion($id, $reason)),
                    'unpublish' => $this->unpublish->handle(new UnpublishQuestion($id, $reason)),
                    default => null,
                };
                $done[] = $id;
            } catch (InvalidModeration) {
                $decided[] = $id;
            } catch (QuestionNotFound) {
                $missing[] = $id;
            }
        }

        return new BulkDecision($done, $decided, $missing);
    }
}
