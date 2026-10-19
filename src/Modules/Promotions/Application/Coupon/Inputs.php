<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use InvalidArgumentException;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * What a caller passes, checked before anything is read (handoff §5.3: every rule in code first, the
 * database's own rules behind it). Ids are compared lowercase.
 */
final class Inputs
{
    /** A code is 3 to 30 Latin letters, digits and hyphens (promotions.md §1.1). */
    private const string CODE = '/^[A-Z0-9-]{3,30}\z/';

    public static function store(string $storeId): StoreId
    {
        try {
            // Lowercased by StoreId itself.
            return StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidPromotionAttribute('store', 'not a store id');
        }
    }

    public static function id(string $id, string $attribute): string
    {
        if (! Ulids::valid($id)) {
            throw new InvalidPromotionAttribute($attribute, 'not an id');
        }

        return strtolower($id);
    }

    /** An order's public number, the one its customer reads: 1 to 40 characters (§5). */
    public static function orderNumber(string $number): string
    {
        $length = mb_strlen($number);

        if ($length < 1 || $length > 40) {
            throw new InvalidPromotionAttribute('order_number', 'must be 1 to 40 characters');
        }

        return $number;
    }

    /**
     * The start of a code as staff type it to search (§1.1): read as a code is — case, Arabic digits,
     * spaces around it. Null when no code can start so: nothing found, and never a LIKE wildcard.
     */
    public static function codeStart(string $typed): ?string
    {
        $start = strtoupper(LatinDigits::of(trim($typed)));

        return preg_match('/\A[A-Z0-9-]{1,30}\z/', $start) === 1 ? $start : null;
    }

    /**
     * The code as a coupon stores it (§1.1): read without regard to case, Arabic digits as 0-9 (the
     * system's rule since 2026-10-06), spaces around it ignored. Null when it cannot be any coupon's.
     */
    public static function code(string $typed): ?string
    {
        $code = strtoupper(LatinDigits::of(trim($typed)));

        return preg_match(self::CODE, $code) === 1 ? $code : null;
    }
}
