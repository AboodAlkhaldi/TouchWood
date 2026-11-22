<?php

declare(strict_types=1);

namespace Modules\Sync\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions Sync's use cases check (sync.md §3). Declared into Access's catalog at boot.
 *
 * **Wiring a store** - the provider, its address, the key - is reserved: held by Super Admins only,
 * never offered in a role (handoff §12.2). **The report** and **running its reads** are per store, in
 * the role editor's Sync group (owner, 2026-10-13). The system's reads and the log's pruning are
 * reserved too.
 *
 * Each permission's handlers arrive with their build step; the module's README says which.
 */
final class SyncPermissions
{
    /** Wiring a store, changing its connection, unwiring it - Super Admins only. */
    public const string CONNECTION_MANAGE = 'sync.connection.manage';

    /** A store's Sync report and its count. */
    public const string REPORT_VIEW = 'sync.report.view';

    /** Retry, Sync now, dismissing an entry or Leave it. */
    public const string READ_RUN = 'sync.read.run';

    /** The reads the system runs: notified, hourly, nightly, one code. */
    public const string READ_SCHEDULED = 'sync.read.scheduled';

    /** Deleting the report's closed entries and the reads' log after 90 days. */
    public const string LOG_PRUNE = 'sync.log.prune';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Sync),
            self::jobs(),
        );

        // Reserved and store-free: a Super Admin's, or the system's.
        $reserved = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
            self::reserved(),
        );

        return [...$jobs, ...$reserved];
    }

    /**
     * @return list<string> the jobs a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [self::REPORT_VIEW, self::READ_RUN];
    }

    /**
     * @return list<string> a Super Admin's or the system's, never in a role
     */
    public static function reserved(): array
    {
        return [self::CONNECTION_MANAGE, self::READ_SCHEDULED, self::LOG_PRUNE];
    }
}
