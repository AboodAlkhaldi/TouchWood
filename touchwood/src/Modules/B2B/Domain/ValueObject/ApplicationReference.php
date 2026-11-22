<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use LogicException;

/**
 * An application's number, given when it is sent (b2b.md §1.2, amendment 14(g)): `TW-CO-`, the year
 * it was sent as two digits, a dash, and that year's count from `0001` — `TW-CO-26-0001`. At least
 * four digits; a year past 9999 simply has more.
 *
 * The system writes every one; nobody types one in. So a malformed value is a bug, not a refusal,
 * and it is a LogicException rather than one of the module's errors.
 */
final readonly class ApplicationReference
{
    private const string PATTERN = '/\ATW-CO-(\d{2})-(\d{4,})\z/';

    private function __construct(
        public string $value,
    ) {}

    /**
     * @param  int  $year  the year it was sent, all four digits — in its home store's time zone
     * @param  int  $number  that year's count, from 1
     */
    public static function of(int $year, int $number): self
    {
        // Two digits name a year only within one century.
        if ($year < 2000 || $year > 2099) {
            throw new LogicException("An application reference cannot be given for the year {$year}.");
        }

        if ($number < 1) {
            throw new LogicException("An application reference counts from 1, not {$number}.");
        }

        return new self(sprintf('TW-CO-%02d-%04d', $year - 2000, $number));
    }

    /**
     * One read back from the database, whose CHECK holds the same shape.
     */
    public static function reconstitute(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1 || (int) $match[2] < 1) {
            throw new LogicException("\"{$value}\" is not an application reference.");
        }

        return new self($value);
    }
}
