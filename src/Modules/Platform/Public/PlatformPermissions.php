<?php

declare(strict_types=1);

namespace Modules\Platform\Public;

/**
 * Every permission Platform checks (Platform spec §3). Access puts them in its permission catalog
 * (Access spec §1.5); their names in Arabic and English are Platform's translations
 * (`platform::permissions`). Reserved permissions belong to Super Admins only and are never
 * offered in the role editor.
 */
final class PlatformPermissions
{
    public const string STORE_CREATE = 'platform.store.create';

    public const string STORE_UPDATE = 'platform.store.update';

    public const string STORE_VIEW = 'platform.store.view';

    public const string CURRENCY_CREATE = 'platform.currency.create';

    public const string CURRENCY_UPDATE = 'platform.currency.update';

    public const string SETTINGS_VIEW = 'platform.settings.view';

    public const string SETTINGS_UPDATE = 'platform.settings.update';

    public const string MEDIA_UPLOAD = 'platform.media.upload';

    public const string MEDIA_UPDATE = 'platform.media.update';

    public const string MEDIA_DELETE = 'platform.media.delete';

    public const string MEDIA_VARIANTS_GENERATE = 'platform.media.variants.generate';

    public const string AUDIT_VIEW = 'platform.audit.view';

    /**
     * Each permission, whether it is reserved for Super Admins, and whether it is store-free: it
     * concerns nothing that belongs to one store, so its handler checks it with
     * PermissionScope::global() (owner's decision, 2026-09-19). The others are per store.
     *
     * @return array<string, array{reserved: bool, storeFree: bool}>
     */
    /**
     * The business area each action is shown under in the role editor and the admin menu (stage 2b,
     * P2). Access turns it into its own PermissionGroup; Platform names it as a string, because
     * Platform sits below Access and cannot see its types. A reserved action is never offered, so
     * it has none.
     *
     * @return array<string, array{reserved: bool, storeFree: bool, group: string|null}>
     */
    public static function all(): array
    {
        return [
            self::STORE_CREATE => ['reserved' => true, 'storeFree' => true, 'group' => null],
            self::STORE_UPDATE => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings'],
            self::STORE_VIEW => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings'],
            self::CURRENCY_CREATE => ['reserved' => true, 'storeFree' => true, 'group' => null],
            self::CURRENCY_UPDATE => ['reserved' => true, 'storeFree' => true, 'group' => null],
            self::SETTINGS_VIEW => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings'],
            // A global setting is checked with PermissionScope::allStores().
            self::SETTINGS_UPDATE => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings'],
            self::MEDIA_UPLOAD => ['reserved' => false, 'storeFree' => true, 'group' => 'media'],
            self::MEDIA_UPDATE => ['reserved' => false, 'storeFree' => true, 'group' => 'media'],
            self::MEDIA_DELETE => ['reserved' => false, 'storeFree' => true, 'group' => 'media'],
            self::MEDIA_VARIANTS_GENERATE => ['reserved' => true, 'storeFree' => true, 'group' => null],
            self::AUDIT_VIEW => ['reserved' => false, 'storeFree' => false, 'group' => 'audit'],
        ];
    }
}
