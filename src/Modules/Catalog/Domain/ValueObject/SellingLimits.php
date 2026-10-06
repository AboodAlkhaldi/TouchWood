<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidSellingTerms;

/**
 * A product's quantity limits in one store, each selling mode its own (catalog.md §1.3, §9.3 #10 as
 * the owner replaced it): how many of one variant one order may hold. Retail's minimum is 1 unless
 * set; each maximum is optional; the wholesale minimum is required while any variant sells wholesale
 * there — a question about the variants, asked by `StoreListing`. Each a whole number from 1 to
 * 100,000, a maximum never below its minimum (§9.5 #1).
 */
final readonly class SellingLimits
{
    public const int MIN = 1;

    public const int MAX = 100_000;

    private function __construct(
        public int $retailMinimum,
        public ?int $retailMaximum,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
    ) {}

    /** A product first chosen in a store: retail from one, no other limit. */
    public static function initial(): self
    {
        return new self(self::MIN, null, null, null);
    }

    /**
     * @throws InvalidSellingTerms
     */
    public static function of(int $retailMinimum, ?int $retailMaximum, ?int $wholesaleMinimum, ?int $wholesaleMaximum): self
    {
        foreach ([$retailMinimum, $retailMaximum, $wholesaleMinimum, $wholesaleMaximum] as $limit) {
            if ($limit !== null && ($limit < self::MIN || $limit > self::MAX)) {
                throw new InvalidSellingTerms('each limit a whole number from '.self::MIN.' to '.self::MAX);
            }
        }

        if ($retailMaximum !== null && $retailMaximum < $retailMinimum) {
            throw new InvalidSellingTerms('a retail maximum never below its minimum');
        }

        if ($wholesaleMaximum !== null && ($wholesaleMinimum === null || $wholesaleMaximum < $wholesaleMinimum)) {
            throw new InvalidSellingTerms('a wholesale maximum never below its minimum');
        }

        return new self($retailMinimum, $retailMaximum, $wholesaleMinimum, $wholesaleMaximum);
    }

    public static function reconstitute(int $retailMinimum, ?int $retailMaximum, ?int $wholesaleMinimum, ?int $wholesaleMaximum): self
    {
        return new self($retailMinimum, $retailMaximum, $wholesaleMinimum, $wholesaleMaximum);
    }
}
