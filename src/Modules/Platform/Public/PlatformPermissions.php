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
     * @return array<string, bool> each permission, and whether it is reserved for Super Admins
     */
    public static function all(): array
    {
        return [
            self::STORE_CREATE => true,
            self::STORE_UPDATE => false,
            self::STORE_VIEW => false,
            self::CURRENCY_CREATE => true,
            self::CURRENCY_UPDATE => true,
            self::SETTINGS_VIEW => false,
            self::SETTINGS_UPDATE => false,
            self::MEDIA_UPLOAD => false,
            self::MEDIA_UPDATE => false,
            self::MEDIA_DELETE => false,
            self::MEDIA_VARIANTS_GENERATE => true,
            self::AUDIT_VIEW => false,
        ];
    }
}
