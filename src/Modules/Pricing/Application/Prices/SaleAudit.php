<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Domain\ValueObject\Sale;

/**
 * A sale's audit entry (pricing.md §3: every change audited by value, from and to).
 */
final class SaleAudit
{
    public static function entry(string $action, ?Sale $before, ?Sale $after, int $exponent): AuditEntryDto
    {
        $sale = $after ?? $before;
        $changes = AuditChanges::none();

        foreach (['percent_bp', 'price', 'starts_at', 'ends_at', 'always_wins', 'sizes'] as $field) {
            $from = $before === null ? null : self::value($before, $field, $exponent);
            $to = $after === null ? null : self::value($after, $field, $exponent);

            if ($from !== $to) {
                $changes->changed($field, $from, $to);
            }
        }

        return new AuditEntryDto($action, 'pricing.sale', (string) $sale?->id, $sale?->storeId, $changes);
    }

    /**
     * @return int|bool|string|list<string>|null
     */
    private static function value(Sale $sale, string $field, int $exponent): int|bool|string|array|null
    {
        return match ($field) {
            'percent_bp' => $sale->percentBasisPoints,
            'price' => $sale->price?->toDecimal($exponent),
            'starts_at' => $sale->startsAt->format(DATE_ATOM),
            'ends_at' => $sale->endsAt?->format(DATE_ATOM),
            'always_wins' => $sale->alwaysWins,
            default => $sale->variantIds,
        };
    }
}
