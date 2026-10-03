<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Audit;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What changes to the shared lists leave in the audit log (catalog.md §3: "every change is audited,
 * by value — product data names no person"). A list's words are the business's, so every column is
 * recorded by value. The lists belong to no store, so their entries have none; a store's order of
 * its menu is recorded in that store.
 *
 * The subject is `catalog.{list}` (`catalog.brand`, `catalog.category`…), the action
 * `catalog.{list}.{what}`, named in both languages in `catalog::audit`.
 */
final class ListAudit
{
    /**
     * Every column as it now is.
     *
     * @param  array<string, string|int|bool|null>  $now  the model's snapshot
     */
    public static function added(string $subject, string $id, array $now, ?string $storeId = null): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach ($now as $column => $value) {
            if ($value !== null) {
                $changes->changed($column, null, $value);
            }
        }

        return self::entry($subject, 'added', $id, $changes, $storeId);
    }

    /**
     * The columns that changed, from what they were to what they are.
     *
     * @param  array<string, string|int|bool|null>  $before  the model's pulled changes
     * @param  array<string, string|int|bool|null>  $now  its snapshot
     */
    public static function changed(string $subject, string $what, string $id, array $before, array $now, ?string $storeId = null): ?AuditEntryDto
    {
        if ($before === []) {
            return null;
        }

        $changes = AuditChanges::none();
        ksort($before);

        foreach ($before as $column => $was) {
            $changes->changed($column, $was, $now[$column] ?? null);
        }

        return self::entry($subject, $what, $id, $changes, $storeId);
    }

    /**
     * What the row held when it went.
     *
     * @param  array<string, string|int|bool|null>  $was  its snapshot before the delete
     */
    public static function deleted(string $subject, string $id, array $was): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach ($was as $column => $value) {
            if ($value !== null) {
                $changes->changed($column, $value, null);
            }
        }

        return self::entry($subject, 'deleted', $id, $changes, null);
    }

    private static function entry(string $subject, string $what, string $id, AuditChanges $changes, ?string $storeId): AuditEntryDto
    {
        return new AuditEntryDto("catalog.{$subject}.{$what}", "catalog.{$subject}", $id, $storeId, $changes);
    }
}
