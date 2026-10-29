<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Delivery;

/**
 * Starts sending a notice or a staff member's message on the queue (ops.md §1.7: "sending to thousands
 * goes in batches on the queue"). Called inside the transaction that saves it: the queue's connection
 * waits for the commit, so a rolled-back save sends nothing.
 */
interface BroadcastQueue
{
    public function start(Broadcast $broadcast, string $id): void;
}
