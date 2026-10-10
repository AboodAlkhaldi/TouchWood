<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Settings;

use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Each store's points programme (loyalty.md §1.5): **Platform settings declared by Loyalty**, one set
 * per store, changed only under `loyalty.settings.update`, which only an admin role may hold. Values
 * are read when they are used — earning reads them at delivery, a redemption at checkout — so a
 * change never rewrites what was already earned or spent.
 *
 * The programme starts **off** in every store until an admin turns it on (owner, 2026-10-07); the
 * other defaults and every range are the spec's, accepted with it (2026-10-10).
 */
final class ProgrammeSettings
{
    /** The store's programme on or off. Off: nothing is earned or redeemed; balances and expiry go on. */
    public const string ENABLED = 'loyalty.points.enabled';

    /** Points earned per 1 unit of the store's currency spent. */
    public const string EARN_PER_UNIT = 'loyalty.points.earn_per_unit';

    /** Points for 1 unit of currency off. */
    public const string POINTS_PER_UNIT_OFF = 'loyalty.points.points_per_unit_off';

    /** How long a lot lives, from the day it came in. */
    public const string EXPIRY_MONTHS = 'loyalty.points.expiry_months';

    /** The fewest points one redemption may charge. */
    public const string MINIMUM_REDEMPTION = 'loyalty.points.minimum_redemption';

    /** The most of an order's net subtotal points may pay. */
    public const string MAX_REDEMPTION_PERCENT = 'loyalty.points.max_redemption_percent';

    /** Individuals choose how many points to use; off: their whole balance or none. */
    public const string PUBLIC_PARTIAL = 'loyalty.redemption.public_partial';

    /** The same for approved companies, set apart from individuals. */
    public const string COMPANY_PARTIAL = 'loyalty.redemption.company_partial';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        $boolean = static fn (string $key, bool $default): SettingDefinitionDto => new SettingDefinitionDto(
            $key, SettingScope::Store, SettingType::Boolean, [], $default, LoyaltyPermissions::SETTINGS_UPDATE,
        );
        $integer = static fn (string $key, int $min, int $max, int $default): SettingDefinitionDto => new SettingDefinitionDto(
            $key, SettingScope::Store, SettingType::Integer, ['min:'.$min, 'max:'.$max], $default, LoyaltyPermissions::SETTINGS_UPDATE,
        );

        return [
            $boolean(self::ENABLED, false),
            $integer(self::EARN_PER_UNIT, 1, 1_000, 1),
            $integer(self::POINTS_PER_UNIT_OFF, 1, 100_000, 100),
            $integer(self::EXPIRY_MONTHS, 1, 120, 12),
            $integer(self::MINIMUM_REDEMPTION, 0, 1_000_000, 0),
            $integer(self::MAX_REDEMPTION_PERCENT, 0, 100, 50),
            $boolean(self::PUBLIC_PARTIAL, true),
            $boolean(self::COMPANY_PARTIAL, true),
        ];
    }
}
