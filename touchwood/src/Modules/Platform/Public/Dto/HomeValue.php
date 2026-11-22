<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Shared\Domain\ValueObject\Money;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One value a home card shows (dashboard.md §6.1): a count, a rating out of 5, or an amount of money - written
 * by the server as the panel writes money (`MoneyData`: Latin digits, the currency's sign), in the store's own
 * currency, never converted (§3.2). The page writes a count and a rating in Latin digits itself.
 */
#[TypeScript]
final readonly class HomeValue
{
    public const string COUNT = 'count';

    public const string MONEY = 'money';

    public const string RATING = 'rating';

    public function __construct(
        /** self::COUNT, self::MONEY or self::RATING. */
        public string $unit,
        /** The count; the rating, to one decimal; money's smallest units, to compare and to draw. */
        public int|float $value,
        /** Money as written; null for a count or a rating. */
        public ?MoneyData $money = null,
    ) {}

    public static function count(int $count): self
    {
        return new self(self::COUNT, $count);
    }

    public static function rating(float $rating): self
    {
        return new self(self::RATING, round($rating, 1));
    }

    public static function money(Money $money, StoreDto $store, string $locale): self
    {
        return new self(self::MONEY, $money->minorUnits, MoneyData::of($money, $store, $locale));
    }
}
