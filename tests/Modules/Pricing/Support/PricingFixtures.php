<?php

declare(strict_types=1);

namespace Tests\Modules\Pricing\Support;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Pricing\Application\Command\RemoveRetailPrice\RemoveRetailPrice;
use Modules\Pricing\Application\Command\RemoveRetailPrice\RemoveRetailPriceHandler;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPrice;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPriceHandler;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use stdClass;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * What Pricing's tests need around them - kept here, not as global functions, so two test files never
 * declare the same name (lesson 43).
 */
final class PricingFixtures
{
    /**
     * A store's id, on or off: Platform's lookup by code answers only the stores that are on.
     */
    public static function storeId(string $code): string
    {
        return (string) DB::table('platform.stores')->where('code', $code)->value('id');
    }

    /**
     * A store in a currency with three decimals, created off as every new store is: Kuwait, in
     * dinars. Its id.
     */
    public static function kuwait(): string
    {
        Fx::asSystem(function (): void {
            app(CreateCurrencyHandler::class)->handle(new CreateCurrency('KWD', 3, 'دينار كويتي', 'Kuwaiti Dinar', 'د.ك', 'KWD', null));
            app(CreateStoreHandler::class)->handle(new CreateStore('kw', 'الكويت', 'Kuwait', 'KW', 'KWD', 0, 'Asia/Kuwait', 4));
        });

        return self::storeId('kw');
    }

    /**
     * @param  array<string, string>  $prices  variant id => amount
     */
    public static function set(string $storeCode, array $prices): void
    {
        Fx::asSystem(fn () => app(SetRetailPriceHandler::class)->handle(new SetRetailPrice(self::storeId($storeCode), $prices)));
    }

    /**
     * @param  list<string>  $variantIds
     */
    public static function remove(string $storeCode, array $variantIds): void
    {
        Fx::asSystem(fn () => app(RemoveRetailPriceHandler::class)->handle(new RemoveRetailPrice(self::storeId($storeCode), $variantIds)));
    }

    public static function retail(string $storeCode, string $variantId): ?stdClass
    {
        $row = DB::table('pricing.retail_prices')->where('store_id', self::storeId($storeCode))->where('variant_id', $variantId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * A size's candidates in a store, each as text, in one order.
     *
     * @return list<string>
     */
    public static function candidates(string $storeCode, string $variantId): array
    {
        $rows = DB::table('pricing.price_candidates')->where('store_id', self::storeId($storeCode))->where('variant_id', $variantId)->get();
        $written = [];

        foreach ($rows as $row) {
            $written[] = implode(' ', [$row->mode, $row->from_quantity, $row->amount_minor, $row->currency_code, $row->kind, $row->always_wins ? 'always' : '-', $row->starts_at ?? '-', $row->ends_at ?? '-', $row->source_id ?? '-']);
        }

        sort($written);

        return $written;
    }

    /**
     * Every candidate in every store, as text, in one order: what the repair must write again.
     *
     * @return list<string>
     */
    public static function allCandidates(): array
    {
        $written = array_map(static fn (stdClass $row): string => implode(' ', [
            $row->store_id, $row->variant_id, $row->mode, $row->from_quantity, $row->amount_minor, $row->currency_code,
            $row->kind, $row->always_wins ? 'always' : '-', $row->starts_at ?? '-', $row->ends_at ?? '-', $row->source_id ?? '-',
        ]), DB::table('pricing.price_candidates')->get()->all());
        sort($written);

        return $written;
    }

    /**
     * What Catalog was told of a size in a store: [price now, price before, currency], or null when
     * nothing was.
     *
     * @return array{?int, ?int, ?string}|null
     */
    public static function told(string $storeCode, string $variantId): ?array
    {
        $row = DB::table('catalog.store_variant_facts')->where('store_id', self::storeId($storeCode))->where('variant_id', $variantId)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            $row->price_minor === null ? null : (int) $row->price_minor,
            $row->price_before_minor === null ? null : (int) $row->price_before_minor,
            $row->currency === null ? null : (string) $row->currency,
        ];
    }

    /**
     * The invalid input this work was refused with, or null when it was not.
     */
    public static function invalid(callable $work): ?InvalidPricingInput
    {
        try {
            $work();
        } catch (InvalidPricingInput $error) {
            return $error;
        }

        return null;
    }

    /**
     * The latest audit entry with this action and subject (the log is append-only).
     */
    public static function lastAudit(string $action, string $subjectId): ?stdClass
    {
        $row = DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->orderByDesc('id')->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * What the latest audit entry with this action and subject changed.
     *
     * @return array<string, mixed>
     */
    public static function auditChanges(string $action, string $subjectId): array
    {
        /** @var array<string, mixed> $changes */
        $changes = json_decode((string) self::lastAudit($action, $subjectId)?->changes, true, flags: JSON_THROW_ON_ERROR);

        return $changes;
    }
}
