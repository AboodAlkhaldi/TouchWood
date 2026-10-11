<?php

declare(strict_types=1);

namespace Tests\Modules\Loyalty\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Model\OrderPoints;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsLedger;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Modules\Loyalty\Public\Dto\EarnPoints;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Dto\RedemptionRequest;
use Modules\Loyalty\Public\Enums\Audience;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use RuntimeException;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * Loyalty's tests calling it as Sales will (loyalty.md §2.1). **Sales does not exist yet**, so its two
 * permissions are declared here, in each test's own app, exactly as sales.md §3 names them — a
 * stand-in: nothing of Sales ships with Loyalty (plan, step 3).
 */
final class LoyaltyFixtures
{
    /** Sales's placing an order: every customer, per store (sales.md §3). */
    public const string PLACE = 'sales.order.place';

    /** Sales's delivery: a role's job, per store (sales.md §3). */
    public const string FULFIL = 'sales.order.fulfil';

    /** Sales's system work: reserved and store-free (sales.md §3) — never a caller's permission. */
    public const string SYSTEM = 'sales.system.run';

    public static function standInForSales(): void
    {
        app(PermissionCatalog::class)->declare(
            'sales',
            new PermissionDefinitionDto(self::PLACE, PermissionAudience::EveryCustomer, kind: PermissionKind::PerStore),
            new PermissionDefinitionDto(self::FULFIL, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Orders),
            new PermissionDefinitionDto(self::SYSTEM, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
        );
    }

    /**
     * The store's programme switched on, with any other settings given, as an admin would save them.
     *
     * @param  array<string, int|bool>  $settings
     */
    public static function programme(string $storeCode = 'sa', array $settings = []): void
    {
        foreach ([ProgrammeSettings::ENABLED => true, ...$settings] as $key => $value) {
            Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, $storeCode, $value)));
        }
    }

    public static function api(): LoyaltyApi
    {
        return app(LoyaltyApi::class);
    }

    public static function currency(string $storeCode = 'sa'): string
    {
        return app(PlatformApi::class)->storeByCode($storeCode)->currencyCode
            ?? throw new RuntimeException("Store {$storeCode} is not seeded.");
    }

    public static function money(int $minor, string $storeCode = 'sa'): Money
    {
        return Money::of($minor, self::currency($storeCode));
    }

    public static function placing(): CallerPermission
    {
        return new CallerPermission('sales', self::PLACE);
    }

    public static function delivering(): CallerPermission
    {
        return new CallerPermission('sales', self::FULFIL);
    }

    public static function orderId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** An order delivered by the system, in a transaction of its own; answers the order's id. */
    public static function deliver(string $customerId, int $goodsMinor, string $storeCode = 'sa', ?string $orderId = null): string
    {
        $orderId ??= self::orderId();
        $earn = new EarnPoints($orderId, 'T-'.substr($orderId, -8), $customerId, Fx::storeId($storeCode), self::money($goodsMinor, $storeCode), self::delivering());

        Fx::asSystem(fn () => DB::transaction(fn () => self::api()->earn($earn)));

        return $orderId;
    }

    public static function quote(string $customerId, ?int $asked, int $netMinor, Audience $audience = Audience::Public, string $storeCode = 'sa'): RedemptionQuote
    {
        return self::api()->quoteRedemption(new RedemptionRequest($customerId, Fx::storeId($storeCode), $audience, $asked, self::money($netMinor, $storeCode)));
    }

    /** The quote placed as it stands, by the customer, in a transaction of its own. */
    public static function place(string $customerId, RedemptionQuote $quote, int $netMinor, Audience $audience = Audience::Public, string $storeCode = 'sa', ?string $orderId = null): string
    {
        $orderId ??= self::orderId();
        $redeem = new RedeemPoints(
            $orderId, 'T-'.substr($orderId, -8), $customerId, Fx::storeId($storeCode), $quote->pointsCharged, $quote->discount,
            $audience, self::money($netMinor, $storeCode), self::placing(),
        );

        Fx::actAsCustomer($customerId);
        DB::transaction(fn () => self::api()->redeem($redeem));

        return $orderId;
    }

    /**
     * The real ledger as a call racing another sees it (no second process needed): the order not yet
     * there on the first read, or under the lock too; or recorded by the other call just before this
     * one writes — its write then answers false and writes nothing, as the insert-if-absent does.
     */
    public static function racingLedger(bool $hideUnderLock = false, bool $recordedMeanwhile = false): void
    {
        $real = new DatabasePointsLedger(DB::connection());

        app()->instance(PointsLedger::class, new class($real, $hideUnderLock, $recordedMeanwhile) implements PointsLedger
        {
            public function __construct(
                private PointsLedger $real,
                private bool $hideUnderLock,
                private bool $recordedMeanwhile,
            ) {}

            public function order(string $orderId): ?OrderPoints
            {
                return null;
            }

            public function lockOrder(string $orderId): ?OrderPoints
            {
                return $this->hideUnderLock ? null : $this->real->lockOrder($orderId);
            }

            public function redeem(PointsAccount $account, string $orderId, string $orderNumber, string $currencyCode, int $points, int $discountMinor, int $capPercent, DateTimeImmutable $now): bool
            {
                return ! $this->recordedMeanwhile && $this->real->redeem($account, $orderId, $orderNumber, $currencyCode, $points, $discountMinor, $capPercent, $now);
            }

            public function earn(PointsAccount $account, string $orderId, string $orderNumber, string $currencyCode, int $goodsTotalMinor, int $points, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
            {
                return ! $this->recordedMeanwhile && $this->real->earn($account, $orderId, $orderNumber, $currencyCode, $goodsTotalMinor, $points, $expiresAt, $now);
            }
        });
    }
}
