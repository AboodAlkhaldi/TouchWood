<?php

declare(strict_types=1);

namespace Modules\Platform\Public;

/**
 * Every permission Platform checks (Platform spec §3). Access puts them in its permission catalog
 * (Access spec §1.5); their names in Arabic and English are Platform's translations
 * (`platform::permissions`). Reserved permissions belong to Super Admins only and are never
 * offered in the role editor. Admin-only permissions go only into admin roles, which only a Super
 * Admin edits; Access adds them to its own list of management actions and enforces it.
 */
final class PlatformPermissions
{
    public const string STORE_CREATE = 'platform.store.create';

    public const string STORE_UPDATE = 'platform.store.update';

    public const string STORE_VIEW = 'platform.store.view';

    /**
     * Turning a store on or off (owner, 2026-10-01; platform.md §3). Reserved: only a Super Admin
     * holds it. Store-free: the switch decides whether a store is a place to work in at all, so no
     * store's own staff can be the ones to hold it. Holding it is also what lets the stores screen
     * show an off store (§1.6).
     */
    public const string STORE_SWITCH = 'platform.store.switch';

    public const string CURRENCY_CREATE = 'platform.currency.create';

    public const string CURRENCY_UPDATE = 'platform.currency.update';

    public const string SETTINGS_VIEW = 'platform.settings.view';

    public const string SETTINGS_UPDATE = 'platform.settings.update';

    public const string MEDIA_UPLOAD = 'platform.media.upload';

    public const string MEDIA_UPDATE = 'platform.media.update';

    public const string MEDIA_DELETE = 'platform.media.delete';

    public const string MEDIA_VARIANTS_GENERATE = 'platform.media.variants.generate';

    /**
     * Private files — a company's papers — exist in the media library only for holders: name, date,
     * where used; never opened there. A holder describes or deletes one with the usual permission on
     * top, and the audit log withholds which private file an entry is about from anyone else (B2B
     * step 3, amendments 5, 6 and 8). It adds them to a library the person may already open; it
     * opens the library to nobody. Choosing "private" when uploading in the library also needs it.
     */
    public const string MEDIA_PRIVATE_VIEW = 'platform.media.private.view';

    public const string AUDIT_VIEW = 'platform.audit.view';

    /**
     * The queue's failed work: seeing it, a job's whole error, retrying one, deleting one (owner,
     * 2026-09-29; platform.md §3). One permission for all of it. Admin-only: an error can quote the
     * values a job was writing, personal data among them. Global: a job belongs to no store.
     */
    public const string JOBS_MANAGE = 'platform.jobs.manage';

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
     * Whether it is admin-only: a role action that only an admin role may hold (B2B step 3, amendment
     * 5). Platform only says so; Access, which owns roles, enforces it.
     *
     * @return array<string, array{reserved: bool, storeFree: bool, group: string|null, adminOnly: bool}>
     */
    public static function all(): array
    {
        return [
            self::STORE_CREATE => ['reserved' => true, 'storeFree' => true, 'group' => null, 'adminOnly' => false],
            self::STORE_UPDATE => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings', 'adminOnly' => false],
            self::STORE_VIEW => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings', 'adminOnly' => false],
            self::STORE_SWITCH => ['reserved' => true, 'storeFree' => true, 'group' => null, 'adminOnly' => false],
            self::CURRENCY_CREATE => ['reserved' => true, 'storeFree' => true, 'group' => null, 'adminOnly' => false],
            self::CURRENCY_UPDATE => ['reserved' => true, 'storeFree' => true, 'group' => null, 'adminOnly' => false],
            self::SETTINGS_VIEW => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings', 'adminOnly' => false],
            // A global setting is checked with PermissionScope::allStores().
            self::SETTINGS_UPDATE => ['reserved' => false, 'storeFree' => false, 'group' => 'store_settings', 'adminOnly' => false],
            self::MEDIA_UPLOAD => ['reserved' => false, 'storeFree' => true, 'group' => 'media', 'adminOnly' => false],
            self::MEDIA_UPDATE => ['reserved' => false, 'storeFree' => true, 'group' => 'media', 'adminOnly' => false],
            self::MEDIA_DELETE => ['reserved' => false, 'storeFree' => true, 'group' => 'media', 'adminOnly' => false],
            // A Super Admin always holds it; an admin when a Super Admin gives it to their role.
            self::MEDIA_PRIVATE_VIEW => ['reserved' => false, 'storeFree' => true, 'group' => 'media', 'adminOnly' => true],
            self::MEDIA_VARIANTS_GENERATE => ['reserved' => true, 'storeFree' => true, 'group' => null, 'adminOnly' => false],
            self::AUDIT_VIEW => ['reserved' => false, 'storeFree' => false, 'group' => 'audit', 'adminOnly' => false],
            // A Super Admin always holds it; an admin when a Super Admin gives it to their role.
            self::JOBS_MANAGE => ['reserved' => false, 'storeFree' => true, 'group' => 'system', 'adminOnly' => true],
        ];
    }
}
