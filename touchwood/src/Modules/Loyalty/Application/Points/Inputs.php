<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use InvalidArgumentException;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Model\Programme;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * What a caller passes, checked before anything is read or written (handoff §5.3: every rule in code
 * first, the database's own rules behind it). Ids are compared lowercase.
 */
final class Inputs
{
    public static function store(string $storeId): StoreId
    {
        try {
            // Lowercased by StoreId itself.
            return StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidPointsAttribute('store', 'not a store id');
        }
    }

    public static function id(string $id, string $attribute): string
    {
        if (! Ulids::valid($id)) {
            throw new InvalidPointsAttribute($attribute, 'not an id');
        }

        return strtolower($id);
    }

    /** An order's public number, the one its customer reads: 1 to 40 characters (§5). */
    public static function orderNumber(string $number): string
    {
        $length = mb_strlen($number);

        if ($length < 1 || $length > 40) {
            throw new InvalidPointsAttribute('order_number', 'must be 1 to 40 characters');
        }

        return $number;
    }

    /** An amount in the store's own currency, never below 0. */
    public static function amount(Money $amount, Programme $programme): int
    {
        if ($amount->currencyCode !== $programme->currencyCode) {
            throw new InvalidPointsAttribute('currency', "not the store's currency, {$programme->currencyCode}");
        }

        if ($amount->minorUnits < 0) {
            throw new InvalidPointsAttribute('amount', 'must not be below 0');
        }

        return $amount->minorUnits;
    }

    /** The most points one change by hand may move: far inside the balance column's range. */
    public const int MOST_BY_HAND = 1_000_000_000;

    /** Points given or taken by hand: a positive whole number (§7), up to the most one change moves. */
    public static function points(int $points): int
    {
        if ($points < 1) {
            throw new InvalidPointsAttribute('points', 'must be a positive whole number');
        }

        if ($points > self::MOST_BY_HAND) {
            throw new InvalidPointsAttribute('points', 'too many for one change');
        }

        return $points;
    }

    /** A change by hand's reason: 1 to 500 characters, spaces around it dropped (§1.9). */
    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 1 || $length > 500) {
            throw new InvalidPointsAttribute('reason', 'must be 1 to 500 characters');
        }

        return $reason;
    }
}
