<?php

declare(strict_types=1);

namespace Modules\Ops\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Ops' permissions (ops.md §3): the six jobs a role may hold, per store, under Messages and Reports
 * (access.md amendment 86) - a message to many for admins only (owner, round 1 #18).
 *
 * Reading and marking one's own inbox needs no role (§3: "none (own)"): it is held automatically, as
 * Feedback's `feedback.own.view` is - one for every customer (the shop's bell), one for every staff
 * member (the panel's bell). The nightly removal of old rows is the system's, reserved, and so is the
 * SMS countries list: Super Admins only (owner, 2026-10-29; access.md amendment 90).
 */
final class OpsPermissions
{
    /** Send a message to one customer of a store (§1.8). */
    public const string MESSAGE_SEND_ONE = 'ops.message.send_one';

    /** Send a message to every customer of a store, or a segment - admin-only (§1.8). */
    public const string MESSAGE_SEND_MANY = 'ops.message.send_many';

    /** Write, schedule and cancel a store's offer notices (§1.7). */
    public const string NOTICE_MANAGE = 'ops.notice.manage';

    /** View a store's newsletter subscribers, remove a guest (§1.9). */
    public const string NEWSLETTER_MANAGE = 'ops.newsletter.manage';

    /** View and download a store's reports (§1.10). */
    public const string REPORT_VIEW = 'ops.report.view';

    /** Make, list and download a store's daily reports; switch on the daily email (§1.11). */
    public const string REPORT_DAILY = 'ops.report.daily';

    /** Read and mark my own inbox in the shop: every customer (§1.2). */
    public const string INBOX_VIEW_OWN = 'ops.inbox.view_own';

    /** Read and mark my own inbox in the panel: every staff member (§1.2). */
    public const string PANEL_INBOX_VIEW_OWN = 'ops.panel_inbox.view_own';

    /** Delete inbox entries, sent messages and handled events older than twelve months: the system only (§5). */
    public const string LOG_PRUNE = 'ops.log.prune';

    /**
     * The SMS countries: which countries' phones SMS reaches, each one's calling code and provider -
     * listing, adding, changing, removing (owner, 2026-10-29; access.md amendment 90). Reserved: Super
     * Admins only, as Platform's places are. Store-free: the list serves every store's customers and
     * every staff member.
     */
    public const string SMS_COUNTRIES_MANAGE = 'ops.sms_countries.manage';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $job = static fn (string $name, bool $adminOnly = false): PermissionDefinitionDto => new PermissionDefinitionDto(
            $name,
            PermissionAudience::Role,
            adminOnly: $adminOnly,
            kind: PermissionKind::PerStore,
            group: PermissionGroup::MessagesAndReports,
        );

        return [
            $job(self::MESSAGE_SEND_ONE),
            $job(self::MESSAGE_SEND_MANY, adminOnly: true),
            $job(self::NOTICE_MANAGE),
            $job(self::NEWSLETTER_MANAGE),
            $job(self::REPORT_VIEW),
            $job(self::REPORT_DAILY),
            new PermissionDefinitionDto(self::INBOX_VIEW_OWN, PermissionAudience::EveryCustomer, kind: PermissionKind::Global),
            new PermissionDefinitionDto(self::PANEL_INBOX_VIEW_OWN, PermissionAudience::EveryStaff, kind: PermissionKind::Global),
            new PermissionDefinitionDto(self::LOG_PRUNE, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
            new PermissionDefinitionDto(self::SMS_COUNTRIES_MANAGE, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
        ];
    }
}
