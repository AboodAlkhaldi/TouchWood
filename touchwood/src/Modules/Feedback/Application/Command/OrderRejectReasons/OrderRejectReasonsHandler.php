<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\OrderRejectReasons;

use Carbon\CarbonImmutable;
use Modules\Feedback\Application\FeedbackAudit;
use Modules\Feedback\Application\Reason\ReasonInputs;
use Modules\Feedback\Application\Reason\ReasonsChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Shared\Application\Unauthorized;

/**
 * Puts the ready reasons in a new order (feedback.md §2.4 B4; drag and Move to Top / Bottom on the screen,
 * the owner's ordering rule): every reason once, nothing else. Each reason that moved is audited.
 */
final readonly class OrderRejectReasonsHandler
{
    public const string PERMISSION = ReasonsChange::PERMISSION;

    public function __construct(
        private ReasonsChange $change,
        private RejectReasons $reasons,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(OrderRejectReasons $command): void
    {
        $this->change->authorize();
        $sent = array_map(ReasonInputs::id(...), $command->reasonIds);

        $this->change->run(function () use ($sent): array {
            $now = [];

            foreach ($this->reasons->all() as $reason) {
                $now[$reason->id] = $reason->position;
            }

            $ids = array_keys($now);
            $sorted = $sent;
            sort($ids);
            sort($sorted);

            if ($ids !== $sorted) {
                throw new InvalidFeedbackAttribute('reasons', 'every ready reason, once');
            }

            $this->reasons->order($sent, CarbonImmutable::now());
            $entries = [];

            foreach ($sent as $index => $id) {
                if ($now[$id] !== $index + 1) {
                    $entries[] = FeedbackAudit::entry('reject_reason', 'ordered', $id, null, ['position' => (string) $now[$id]], ['position' => (string) ($index + 1)]);
                }
            }

            return [null, $entries];
        });
    }
}
