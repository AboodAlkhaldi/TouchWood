<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Service;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * What a completed return refunds (sales.md §1.8), in the order's currency's smallest unit. **By running
 * totals**: for a line of `n` pieces with a share `S`, the first `k` pieces returned carry
 * `floor(S × k ÷ n)`, and each return moves that less what earlier ones moved — so the last piece takes what
 * is left and a line returned in parts lands exactly on `S`. Each received piece refunds its goods share
 * (its part of the line's total less its coupon and points shares, moved as one share) plus its share of
 * the VAT; a gift's is
 * 0. **The shipping fee and its VAT share** go with the return that makes every bought piece returned —
 * once an order.
 */
final class ReturnRefunds
{
    /**
     * @param  array<string, array{quantity: int, total: int, coupon: int, points: int, vat: int, gift: bool}>  $lines  the order's lines
     * @param  array<string, int>  $before  pieces of a line received in returns completed earlier
     * @param  array<string, int>  $now  pieces of a line received in this return
     * @return array{refund: int, goods: int, points: int, shipping: bool} the refund; the goods and points shares it moves (Loyalty's);
     *                                                                     whether it carries the shipping
     */
    public static function of(array $lines, array $before, array $now, bool $shippingRefunded, int $shippingFee, int $shippingVat): array
    {
        $refund = 0;
        $goods = 0;
        $points = 0;

        foreach ($now as $lineId => $pieces) {
            $line = $lines[$lineId] ?? throw new InvalidArgumentException("No line {$lineId} on the order.");
            $earlier = $before[$lineId] ?? 0;

            if ($pieces < 0 || $earlier < 0 || $earlier + $pieces > $line['quantity']) {
                throw new InvalidArgumentException("More pieces of line {$lineId} back than it holds.");
            }

            if ($pieces === 0 || $line['gift']) {
                continue;
            }

            // In whole numbers of any size: a share × pieces may pass PHP's integers.
            $upTo = static fn (int $share, int $taken): BigInteger => BigInteger::of($share)->multipliedBy($taken)->dividedBy($line['quantity'], RoundingMode::Down);
            $move = static fn (int $share): int => $upTo($share, $earlier + $pieces)->minus($upTo($share, $earlier))->toInt();
            // The goods moved as one share — the line's total less its coupon and points shares — so no return's
            // goods go below 0 however the three round.
            $lineGoods = $move($line['total'] - $line['coupon'] - $line['points']);
            $goods += $lineGoods;
            $points += $move($line['points']);
            $refund += $lineGoods + $move($line['vat']);
        }

        // Every bought piece back, counting this return: the shipping too, once.
        $all = $lines !== [];

        foreach ($lines as $lineId => $line) {
            if (! $line['gift'] && ($before[$lineId] ?? 0) + ($now[$lineId] ?? 0) < $line['quantity']) {
                $all = false;
            }
        }

        $shipping = $all && ! $shippingRefunded;

        return ['refund' => $refund + ($shipping ? $shippingFee + $shippingVat : 0), 'goods' => $goods, 'points' => $points, 'shipping' => $shipping];
    }
}
