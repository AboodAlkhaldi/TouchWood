<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Modules\Ops\Application\Alerts\EventDeliveries;
use Modules\Ops\Application\Alerts\EventMessage;

/** Each email or SMS of an event's message is its own job, with its own delivery id (ops.md §6, §7). */
final readonly class QueuedEventDeliveries implements EventDeliveries
{
    public function __construct(
        private Dispatcher $jobs,
    ) {}

    public function emailCustomer(EventMessage $message, string $customerId): void
    {
        $this->jobs->dispatch(new SendEventEmailJob($message, strtolower($customerId), null, (string) Str::uuid()));
    }

    public function emailStaff(EventMessage $message, string $staffId): void
    {
        $this->jobs->dispatch(new SendEventEmailJob($message, null, strtolower($staffId), (string) Str::uuid()));
    }

    public function smsStaff(EventMessage $message, string $staffId): void
    {
        $this->jobs->dispatch(new SendEventSmsJob($message, strtolower($staffId), (string) Str::uuid()));
    }
}
