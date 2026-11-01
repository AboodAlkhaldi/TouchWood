<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\B2B\Public\Events\CompanyApplicationSubmitted;
use Modules\Inventory\Public\Events\LowStockChanged;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\StaffAlerts;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Payments\Public\Events\GatewayKeyExpiring;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\ReturnRequested;
use Modules\Sync\Public\Events\SyncNeedsAttention;

/**
 * Alerts a store's staff (ops.md §1.6), each topic to the holders of its permission there:
 *
 * - S1 a new order - New orders, `sales.order.view`;
 * - S2 a company application - Company applications, `b2b.company.review`;
 * - S3 sizes that became low or ran out at a recount - Low stock, `inventory.stock.manage`; a recount
 *   whose only change is sizes coming back alerts no one (the alert is for sizes that **became** low or
 *   ran out);
 * - S4 a store's reads left problems - Sync problems, `sync.report.view`; bell and email only, never the
 *   critical SMS (owner, 2026-10-28, §9.3 #10);
 * - S9 a customer asked for a return - Returns, `sales.return.manage`;
 * - S5 the gateway key expires in 30, 7 or 1 days - Payment problems, `payments.gateway.manage`, a reserved
 *   permission: super admins alone. At 1 day also the critical SMS (StaffAlerts).
 *
 * S5b has no listener: Payments publishes no `GatewayKeyRejected` yet. `HoldsShort` sends nothing (§1.6). From the queue, retried on failure; once per event
 * (`ops.handled_events`).
 */
final class AlertStaff implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly StaffAlerts $alerts,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(OrderPlaced|CompanyApplicationSubmitted|LowStockChanged|SyncNeedsAttention|ReturnRequested|GatewayKeyExpiring $event): void
    {
        $store = strtolower($event->storeId);

        [$message, $topic, $permission] = match (true) {
            $event instanceof OrderPlaced => [new EventMessage(MessageKind::NewOrder, $store, ['orderId' => strtolower($event->orderId)]), StaffNotificationTopic::NewOrders, 'sales.order.view'],
            $event instanceof CompanyApplicationSubmitted => [new EventMessage(MessageKind::CompanyApplication, $store, ['companyId' => strtolower($event->companyId)]), StaffNotificationTopic::CompanyApplications, 'b2b.company.review'],
            $event instanceof LowStockChanged => [new EventMessage(MessageKind::LowStock, $store, ['low' => count($event->becameLow), 'out' => count($event->ranOut)]), StaffNotificationTopic::LowStock, 'inventory.stock.manage'],
            $event instanceof SyncNeedsAttention => [new EventMessage(MessageKind::SyncProblems, $store, ['readId' => $event->readId]), StaffNotificationTopic::SyncProblems, 'sync.report.view'],
            $event instanceof ReturnRequested => [new EventMessage(MessageKind::ReturnRequested, $store, ['returnId' => strtolower($event->returnId)]), StaffNotificationTopic::Returns, 'sales.return.manage'],
            $event instanceof GatewayKeyExpiring => [new EventMessage(MessageKind::GatewayKeyExpiring, $store, ['days' => $event->daysLeft, 'date' => $event->expiresOn]), StaffNotificationTopic::PaymentProblems, 'payments.gateway.manage'],
        };

        if ($message->kind === MessageKind::LowStock && $message->int('low') + $message->int('out') === 0) {
            return;
        }

        $this->handled->once($event->eventId, self::class, fn () => $this->alerts->alert($message, $topic, $permission));
    }
}
