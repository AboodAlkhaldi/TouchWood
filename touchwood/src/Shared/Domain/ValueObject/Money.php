<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

/**
 * An amount in the smallest unit of its currency: halalas, piastres, fils.
 *
 * Money never knows how many decimals its currency has. That exponent is data on
 * the currencies row, so it is passed in only where decimals matter — parsing
 * user input and formatting for display. Arithmetic never needs it.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public string $currencyCode,
    ) {}

    public static function of(int $minorUnits, string $currencyCode): self
    {
        if (preg_match('/^[A-Z]{3}\z/', $currencyCode) !== 1) {
            throw MoneyException::invalidCurrencyCode($currencyCode);
        }

        return new self($minorUnits, $currencyCode);
    }

    public static function zero(string $currencyCode): self
    {
        return self::of(0, $currencyCode);
    }

    /**
     * Parses a plain decimal such as "1234.50". Input with more decimals than the
     * currency allows is rejected, never rounded.
     */
    public static function fromDecimal(string $amount, string $currencyCode, int $exponent): self
    {
        self::assertExponent($exponent);

        if (preg_match('/^-?\d+(\.\d+)?\z/', $amount) !== 1) {
            throw MoneyException::invalidAmount($amount);
        }

        try {
            $minorUnits = BigDecimal::of($amount)
                ->toScale($exponent, RoundingMode::Unnecessary)
                ->withPointMovedRight($exponent)
                ->toInt();
        } catch (RoundingNecessaryException) {
            throw MoneyException::tooPrecise($amount, $exponent);
        } catch (IntegerOverflowException) {
            throw MoneyException::overflow();
        }

        return self::of($minorUnits, $currencyCode);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->withMinorUnits(BigInteger::of($this->minorUnits)->plus($other->minorUnits));
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->withMinorUnits(BigInteger::of($this->minorUnits)->minus($other->minorUnits));
    }

    /**
     * The factor is an integer or an exact decimal string such as "0.15" — never a
     * float. The caller chooses the rounding so every rounding decision is visible
     * where the amount is computed.
     */
    public function multiply(int|string $factor, RoundingMode $rounding): self
    {
        if (is_string($factor) && preg_match('/^-?\d+(\.\d+)?\z/', $factor) !== 1) {
            throw MoneyException::invalidFactor($factor);
        }

        try {
            $product = BigDecimal::of($this->minorUnits)
                ->multipliedBy($factor)
                ->toScale(0, $rounding)
                ->toBigInteger();
        } catch (RoundingNecessaryException) {
            throw MoneyException::roundingNecessary((string) $factor);
        }

        return $this->withMinorUnits($product);
    }

    /**
     * Splits the amount in proportion to the ratios without losing a minor unit.
     *
     * Each share is first rounded down; the units left over go one each to the
     * shares with the largest remainders, earlier shares winning ties. The shares
     * always sum to exactly the original amount.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ($ratios === []) {
            throw MoneyException::invalidRatios('at least one ratio is required');
        }

        $total = BigInteger::zero();

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw MoneyException::invalidRatios('ratios cannot be negative');
            }

            // Ratios are often line amounts in minor units; their sum can exceed PHP_INT_MAX.
            $total = $total->plus($ratio);
        }

        if ($total->isZero()) {
            throw MoneyException::invalidRatios('ratios cannot all be zero');
        }

        $amount = BigInteger::of($this->minorUnits)->abs();
        $shares = [];
        $remainders = [];
        $distributed = BigInteger::zero();

        foreach (array_values($ratios) as $index => $ratio) {
            [$share, $remainder] = $amount->multipliedBy($ratio)->quotientAndRemainder($total);
            $shares[$index] = $share;
            $remainders[$index] = $remainder;
            $distributed = $distributed->plus($share);
        }

        $leftover = $amount->minus($distributed)->toInt();

        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);
        $receivesLeftover = array_flip(array_slice($order, 0, $leftover));

        $negative = $this->minorUnits < 0;
        $allocation = [];

        foreach ($shares as $index => $share) {
            if (isset($receivesLeftover[$index])) {
                $share = $share->plus(1);
            }

            $allocation[] = $this->withMinorUnits($negative ? $share->negated() : $share);
        }

        return $allocation;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currencyCode === $other->currencyCode && $this->minorUnits === $other->minorUnits;
    }

    /**
     * @return -1|0|1
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    /**
     * For thresholds: "free shipping once goods_total reaches X".
     */
    public function isAtLeast(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    /**
     * Plain decimal for storage-neutral exchange and form inputs: "1234.50".
     */
    public function toDecimal(int $exponent): string
    {
        [$sign, $integer, $fraction] = $this->decimalParts($exponent);

        return $sign.$integer.($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * Display form with Latin digits and thousands separators: "1,234.50".
     * The presentation layer adds the currency: its sign, or its letters in the
     * page's language when it has no sign (the currencies row, CurrencyDto).
     */
    public function format(int $exponent): string
    {
        [$sign, $integer, $fraction] = $this->decimalParts($exponent);

        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);

        return $sign.$grouped.($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * @return array{string, string, string} sign, integer digits, fraction digits
     */
    private function decimalParts(int $exponent): array
    {
        self::assertExponent($exponent);

        $digits = str_pad((string) BigInteger::of($this->minorUnits)->abs(), $exponent + 1, '0', STR_PAD_LEFT);

        return [
            $this->minorUnits < 0 ? '-' : '',
            $exponent === 0 ? $digits : substr($digits, 0, -$exponent),
            $exponent === 0 ? '' : substr($digits, -$exponent),
        ];
    }

    private function withMinorUnits(BigInteger $minorUnits): self
    {
        try {
            return new self($minorUnits->toInt(), $this->currencyCode);
        } catch (MathException) {
            throw MoneyException::overflow();
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode) {
            throw MoneyException::currencyMismatch($this->currencyCode, $other->currencyCode);
        }
    }

    /**
     * @phpstan-assert non-negative-int $exponent
     */
    private static function assertExponent(int $exponent): void
    {
        if ($exponent < 0) {
            throw MoneyException::invalidExponent($exponent);
        }
    }
}
