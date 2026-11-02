<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Modules\Sales\Application\Quote\QuoteView;

/**
 * At Pay, the quote built again from the cart must equal the one shown (§9.5 #4). Compared by what the customer
 * pays and gets: the moments a price was read at, and the points it would earn, are no change.
 */
final class QuoteComparison
{
    /** What moved, for `QuoteChanged`; null when nothing did. */
    public static function moved(QuoteView $shown, QuoteView $now): ?string
    {
        $a = self::parts($shown);
        $b = self::parts($now);

        foreach ($a as $part => $value) {
            if ($value !== $b[$part]) {
                return $part;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function parts(QuoteView $view): array
    {
        $array = $view->toArray();
        /** @var array{lines: list<array<string, mixed>>, gross: int, net: int} $prices */
        $prices = $array['prices'];
        /** @var array{coverage: string, carrierId: ?string, fee: int, price: int, freeAbove: ?int, freeKind: ?string, destination: array<string, ?string>} $shipping */
        $shipping = $array['shipping'];

        return [
            'prices' => [array_map(static function (array $line): array {
                unset($line['endsAt']);

                return $line;
            }, $prices['lines']), $prices['gross'], $prices['net']],
            'coupon' => [$view->couponCode, $view->couponId, $view->couponDiscount->minorUnits, $view->couponShares],
            'points' => [$view->pointsUsed, $view->pointsDiscount->minorUnits],
            'gift' => [$view->giftLevelId, $view->giftVariantId],
            // The rule too, not only the fee it gives today: a later edit works the fee out from it (§1.7 step 5).
            'shipping' => [$shipping['coverage'], $shipping['carrierId'], $shipping['fee'], $shipping['price'], $shipping['freeAbove'], $shipping['freeKind'], $shipping['destination']],
            'totals' => $array['totals'],
        ];
    }
}
