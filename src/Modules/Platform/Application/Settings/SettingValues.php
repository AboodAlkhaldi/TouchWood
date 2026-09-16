<?php

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

    public function forget(): void;
}
