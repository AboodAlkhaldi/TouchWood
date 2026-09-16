<?php

namespace Shared\Domain\ValueObject;

use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;

final class MoneyException extends DomainError
{
    public function type(): string
    {
        return 'shared.invalid_money';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public static function invalidCurrencyCode(string $code): self
    {
        return new self("Invalid currency code \"{$code}\": expected three uppercase letters.");
    }

    public static function currencyMismatch(string $left, string $right): self
    {
        return new self("Cannot combine {$left} with {$right}.");
    }

    public static function invalidAmount(string $amount): self
    {
        return new self("Invalid amount \"{$amount}\": expected a plain decimal such as 1234.50.");
    }

    public static function tooPrecise(string $amount, int $exponent): self
    {
        return new self("Amount \"{$amount}\" has more than {$exponent} decimal places.");
    }

    public static function invalidExponent(int $exponent): self
    {
        return new self("Invalid currency exponent {$exponent}: it cannot be negative.");
    }

    public static function invalidFactor(string $factor): self
    {
        return new self("Invalid factor \"{$factor}\": expected an integer or a plain decimal such as 0.15.");
    }

    public static function roundingNecessary(string $factor): self
    {
        return new self("Multiplying by {$factor} needs rounding, but no rounding was allowed.");
    }

    public static function invalidRatios(string $reason): self
    {
        return new self("Cannot allocate: {$reason}.");
    }

    public static function overflow(): self
    {
        return new self('The amount does not fit in a 64-bit integer.');
    }
}
