<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;

/** The record of every email and SMS (ops.md §5: `ops.sent_messages`) - never a code or a link. */
interface SentMessages
{
    public function record(SentMessage $message): void;

    /** A message's status now; none when its row is gone (the customer anonymized meanwhile, §6). */
    public function status(string $id): ?SendStatus;

    /** A message handed to the provider - QUEUED, or FAILED and retried by hand: SENT, with the provider's id. */
    public function markSent(string $id, ?string $providerId): void;

    /** A QUEUED message the queue gave up on: FAILED (§7). A SENT one stays as it is. */
    public function markFailed(string $id): void;

    /**
     * A message Meta took and later reported **failed** (ops.md §9.8 point 3), found by the id Meta gave: SENT to
     * FAILED, and only from SENT - a status that comes twice, or for a row already failed, changes nothing. A
     * delivered or read status never reaches here: it changes nothing.
     *
     * @return bool whether a row turned
     */
    public function markFailedByProviderId(string $providerId): bool;

    /** Whether any row holds Meta's id: a status that matches none may have beaten the row's own SENT (review L5). */
    public function hasProviderId(string $providerId): bool;

    /** A QUEUED message there was nothing left to send for: its row goes - only sends are recorded. */
    public function forget(string $id): void;
}
