<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * Alerts the staff of a store about a moment there (ops.md §1.4, §1.6): every active staff member who
 * may act on it in that store - holds the topic's permission there, roles nesting - read in one go
 * through `AccessApi::staffToAlert` (access.md amendment 80), each by their own switches for the topic:
 * the bell when the in-panel switch is on, a queued email when the email switch is on.
 *
 * **The critical SMS** (§1.6; §9.3 #10-#12) only for the gateway key expiring in 1 day (S5) or refused
 * (S5b), to the super admins its permission reaches who left their critical-SMS switch on (access.md
 * amendment 81) and have a phone - whatever their other switches say. Nothing else ever goes by SMS: an
 * offer stopped by Odoo's price (S4) is bell and email only.
 *
 * The bell's words name no person, so both languages are written once for everyone. An off store's
 * staff are alerted all the same (owner, 2026-10-29).
 */
final readonly class StaffAlerts
{
    public function __construct(
        private AccessApi $access,
        private PlatformApi $platform,
        private MessageWriter $writer,
        private Inbox $inbox,
        private EventDeliveries $deliveries,
    ) {}

    public function alert(EventMessage $message, StaffNotificationTopic $topic, string $permission): void
    {
        $storeId = StoreId::fromString($message->storeId);
        $store = $this->platform->store($storeId);

        if ($store === null) {
            return;
        }

        $ar = $this->writer->write($message, $store, 'ar', '');
        $en = $this->writer->write($message, $store, 'en', '');

        if ($ar === null || $en === null) {
            return;
        }

        $critical = self::critical($message);
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        foreach ($this->access->staffToAlert($storeId, $topic, $permission) as $staff) {
            if ($staff->byPanel) {
                $this->inbox->add(InboxEntry::forStaff(strtolower((string) Str::ulid()), $staff->staffId, $storeId, $message->kind, $ar->title, $en->title, $ar->line, $en->line, $en->link, $now));
            }

            if ($staff->byEmail) {
                $this->deliveries->emailStaff($message, $staff->staffId);
            }

            // Super admins only (§1.6, §9.3 #11): an admin holding the permission never gets it.
            if ($critical && $staff->criticalSms && $staff->phone !== null && $this->access->staff($staff->staffId)?->isSuperAdmin === true) {
                $this->deliveries->smsStaff($message, $staff->staffId, $topic, $permission);
            }
        }
    }

    /** S5 at 1 day, and S5b (§1.6). */
    public static function critical(EventMessage $message): bool
    {
        return $message->kind === MessageKind::GatewayKeyRejected
            || ($message->kind === MessageKind::GatewayKeyExpiring && $message->int('days') === 1);
    }
}
