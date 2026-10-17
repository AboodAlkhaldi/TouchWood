<?php

declare(strict_types=1);

namespace Tests\Modules\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStock;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStockHandler;
use Modules\Inventory\Application\Command\SetStock\SetStock;
use Modules\Inventory\Application\Command\SetStock\SetStockHandler;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;
use stdClass;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * What Inventory's tests need around them - kept here, not as global functions, so two test files
 * never declare the same name (lesson 43).
 */
final class InventoryFixtures
{
    /**
     * A store's id, on or off: Platform's lookup by code answers only the stores that are on.
     */
    public static function storeId(string $code): string
    {
        return (string) DB::table('platform.stores')->where('code', $code)->value('id');
    }

    /**
     * Every store wired to a provider from now on (no store is until Sync).
     */
    public static function wired(): void
    {
        app()->instance(StoreWiring::class, new class implements StoreWiring
        {
            public function isWired(StoreId $store): bool
            {
                return true;
            }
        });
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function count(string $storeCode, array $counts): void
    {
        Fx::asSystem(fn () => app(SetStockHandler::class)->handle(new SetStock(self::storeId($storeCode), $counts)));
    }

    public static function adjust(string $storeCode, string $variantId, string $direction, int $quantity, string $reason, ?string $note = null): void
    {
        Fx::asSystem(fn () => app(AdjustStockHandler::class)->handle(new AdjustStock(self::storeId($storeCode), $variantId, $direction, $quantity, $reason, $note)));
    }

    public static function stock(string $storeCode, string $variantId): ?stdClass
    {
        $row = DB::table('inventory.stock')->where('store_id', self::storeId($storeCode))->where('variant_id', $variantId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * A size's ledger in a store, oldest first, each line as "REASON before→after note actor".
     *
     * @return list<string>
     */
    public static function ledger(string $storeCode, string $variantId): array
    {
        return array_values(array_map(
            static fn (stdClass $row): string => "{$row->reason} {$row->before}→{$row->after}".($row->note === null ? '' : " {$row->note}")." {$row->actor_type}",
            DB::table('inventory.stock_movements')->where('store_id', self::storeId($storeCode))->where('variant_id', $variantId)->orderBy('id')->get()->all(),
        ));
    }

    /**
     * The invalid input this work was refused with, or null when it was not.
     */
    public static function invalid(callable $work): ?InvalidInventoryInput
    {
        try {
            $work();
        } catch (InvalidInventoryInput $error) {
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
