<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Modules\Access\Public\Enums\StaffNotificationTopic;

/**
 * Puts one email or SMS of an event's message on the queue (ops.md §6: every listener queued, never
 * blocking the sender). Called inside the listener's transaction: the message's `ops.sent_messages` row
 * is written QUEUED there, and its job - which the queue keeps until that transaction commits - sends
 * it and marks it SENT, or FAILED after the queue's last attempt (§7). A listener rolled back leaves
 * neither the row nor the job.
 */
interface EventDeliveries
{
    public function emailCustomer(EventMessage $message, string $customerId): void;

    public function emailStaff(EventMessage $message, string $staffId): void;

    /**
     * The critical SMS (§1.6: the gateway key at 1 day, or refused), to a super admin. The alert's topic
     * and permission go with it: the person's critical-SMS switch is read again when it leaves.
     */
    public function smsStaff(EventMessage $message, string $staffId, StaffNotificationTopic $topic, string $permission): void;
}
