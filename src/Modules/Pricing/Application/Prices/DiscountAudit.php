<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;

/**
 * A category discount's audit entry (pricing.md §3): what changed, from and to, by value, as a sale's.
 */
final class DiscountAudit
{
    public static function entry(string $action, ?CategoryDiscount $before, ?CategoryDiscount $after, int $exponent): AuditEntryDto
    {
        $discount = $after ?? $before;
        $changes = AuditChanges::none();

        foreach (['category', 'percent_bp', 'amount', 'lines', 'starts_at', 'ends_at', 'always_wins', 'left_out'] as $field) {
            $from = $before === null ? null : self::value($before, $field, $exponent);
            $to = $after === null ? null : self::value($after, $field, $exponent);

            if ($from !== $to) {
                $changes->changed($field, $from, $to);
            }
        }

        return new AuditEntryDto($action, 'pricing.category_discount', (string) $discount?->id, $discount?->storeId, $changes);
    }

    /**
     * @return int|bool|string|list<string>|null
     */
    private static function value(CategoryDiscount $discount, string $field, int $exponent): int|bool|string|array|null
    {
        return match ($field) {
            'category' => $discount->categoryId,
            'percent_bp' => $discount->percentBasisPoints,
            'amount' => $discount->amount?->toDecimal($exponent),
            'lines' => $discount->lines->value,
            'starts_at' => $discount->startsAt->format(DATE_ATOM),
            'ends_at' => $discount->endsAt?->format(DATE_ATOM),
            'always_wins' => $discount->alwaysWins,
            default => $discount->excludedProductIds,
        };
    }
}
