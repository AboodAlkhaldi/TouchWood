<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Return;

use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Public\Enums\ReturnReason;
use Shared\Infrastructure\Persistence\Ulids;

/** What a return request is made of (sales.md §1.8), checked before anything is read. */
final class ReturnInputs
{
    /** The most pieces one line of a request may name (an order line holds no more, sales.md §5). */
    public const int MOST_PIECES = 1_000_000;

    /**
     * One of the list, or `OTHER` with words — words only with `OTHER`.
     *
     * @return array{ReturnReason, ?string}
     *
     * @throws InvalidSalesAttribute
     */
    public static function reason(string $reason, ?string $other): array
    {
        $picked = ReturnReason::tryFrom(strtoupper(trim($reason))) ?? throw new InvalidSalesAttribute('reason', 'one of the reasons');
        $words = $other === null || trim($other) === '' ? null : $other;

        if ($picked === ReturnReason::Other) {
            return [$picked, OrderInputs::words($words ?? '')];
        }

        return $words === null ? [$picked, null] : throw new InvalidSalesAttribute('reason', 'words only with Other');
    }

    /**
     * The pieces asked: an order line (a part, or the gift) and how many, each line once.
     *
     * @param  list<array{orderLineId: string, quantity: int}>  $lines
     * @return array<string, int> order line id => pieces
     *
     * @throws InvalidSalesAttribute
     */
    public static function lines(array $lines): array
    {
        $asked = [];

        foreach ($lines as $line) {
            $id = strtolower(trim($line['orderLineId']));

            if (! Ulids::valid($id) || isset($asked[$id])) {
                throw new InvalidSalesAttribute('lines', 'each line of the order once');
            }

            if ($line['quantity'] < 1 || $line['quantity'] > self::MOST_PIECES) {
                throw new InvalidSalesAttribute('quantity', 'from 1 to '.self::MOST_PIECES);
            }

            $asked[$id] = $line['quantity'];
        }

        return $asked !== [] ? $asked : throw new InvalidSalesAttribute('lines', 'at least one line');
    }

    /**
     * The pieces that came back: for each order line once, how many came and how many of those go back
     * into stock.
     *
     * @param  list<array{orderLineId: string, received: int, restocked: int}>  $lines
     * @return array<string, array{int, int}> order line id => [received, restocked]
     *
     * @throws InvalidSalesAttribute
     */
    public static function received(array $lines): array
    {
        $counts = [];

        foreach ($lines as $line) {
            $id = strtolower(trim($line['orderLineId']));

            if (! Ulids::valid($id) || isset($counts[$id])) {
                throw new InvalidSalesAttribute('lines', 'every line of the return, once');
            }

            if ($line['received'] < 0 || $line['received'] > self::MOST_PIECES || $line['restocked'] < 0 || $line['restocked'] > $line['received']) {
                throw new InvalidSalesAttribute('quantity', 'none to all that came, and no more back into stock');
            }

            $counts[$id] = [$line['received'], $line['restocked']];
        }

        return $counts !== [] ? $counts : throw new InvalidSalesAttribute('lines', 'every line of the return, once');
    }
}
