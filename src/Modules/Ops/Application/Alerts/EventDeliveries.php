<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

/**
 * Puts one email or SMS of an event's message on the queue (ops.md §6: every listener queued, never
 * blocking the sender): each its own job, sent once by its delivery id, retried on a provider's
 * failure and recorded as failed after the last attempt (§7). Called inside the listener's transaction:
 * the queue keeps the job until it commits.
 */
interface EventDeliveries
{
    public function emailCustomer(EventMessage $message, string $customerId): void;

    public function emailStaff(EventMessage $message, string $staffId): void;

    /** The critical SMS (§1.6: the gateway key at 1 day, or refused), to a super admin. */
    public function smsStaff(EventMessage $message, string $staffId): void;
}
