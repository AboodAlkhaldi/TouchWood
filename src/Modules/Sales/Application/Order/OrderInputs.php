<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Public\Enums\CancelReason;

/** What an order is changed with, read once for every command. */
final class OrderInputs
{
    /** The most a reason's words may hold (sales.md §5: `decision_reason`, `cancel_other`). */
    public const int MOST = 500;

    /** @throws InvalidSalesAttribute */
    public static function words(string $words): string
    {
        $text = trim($words);

        return $text !== '' && mb_strlen($text) <= self::MOST ? $text : throw new InvalidSalesAttribute('reason', 'words from 1 to '.self::MOST.' characters');
    }

    /**
     * A customer's reason: one of the list, or `OTHER` with words — words only with `OTHER`.
     *
     * @return array{CancelReason, ?string}
     *
     * @throws InvalidSalesAttribute
     */
    public static function customerReason(string $reason, ?string $other): array
    {
        $picked = CancelReason::tryFrom(strtoupper(trim($reason)));

        if ($picked === null || $picked === CancelReason::ByStaff) {
            throw new InvalidSalesAttribute('reason', 'one of the reasons');
        }

        $words = $other === null || trim($other) === '' ? null : $other;

        if ($picked === CancelReason::Other) {
            return [$picked, self::words($words ?? '')];
        }

        return $words === null ? [$picked, null] : throw new InvalidSalesAttribute('reason', 'words only with Other');
    }
}
