<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Settings;

/**
 * Stored setting values. Reads come from cache, because modules read settings on hot paths
 * such as OTP limits.
 */
interface SettingValues
{
    /**
     * @param  string|null  $storeId  null for a global setting
     */
    public function find(string $key, ?string $storeId): ?StoredSetting;

    /**
     * Reads the stored row and locks it for the rest of the transaction.
     */
    public function lockForUpdate(string $key, ?string $storeId): ?StoredSetting;

    /**
     * Inserts or replaces the value and returns the row id.
     */
    public function save(string $key, ?string $storeId, mixed $value, ?string $updatedBy): int;

    /**
     * Call inside the transaction that changes a setting; takes effect once it commits.
     */
    public function invalidate(): void;
}
