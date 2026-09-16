<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Model;

use Modules\Platform\Domain\Exception\CurrencyExponentLocked;
use Modules\Platform\Domain\Exception\InvalidCurrencyAttribute;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\TranslatedText;

/**
 * A currency and how its prices are written (Platform spec §1.2).
 *
 * A price shows the sign when there is one, otherwise the abbreviation. Clearing the sign —
 * because it has no Unicode character yet or the site's font cannot draw it — switches every
 * price in that currency to letters without a deploy.
 */
final class Currency
{
    private const int MAX_EXPONENT = 6;

    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly CurrencyCode $code,
        private int $exponent,
        private TranslatedText $name,
        private TranslatedText $abbreviation,
        private ?string $sign,
    ) {
        self::assertExponent($exponent);
        self::assertSign($sign);
    }

    public static function create(CurrencyCode $code, int $exponent, TranslatedText $name, TranslatedText $abbreviation, ?string $sign): self
    {
        return new self($code, $exponent, $name, $abbreviation, $sign);
    }

    public static function reconstitute(CurrencyCode $code, int $exponent, TranslatedText $name, TranslatedText $abbreviation, ?string $sign): self
    {
        return new self($code, $exponent, $name, $abbreviation, $sign);
    }

    /**
     * @param  bool  $inUse  whether any store uses this currency; the exponent is then locked,
     *                       because changing it would reinterpret every stored amount
     */
    public function changeExponent(int $exponent, bool $inUse): void
    {
        self::assertExponent($exponent);

        if ($this->exponent === $exponent) {
            return;
        }

        if ($inUse) {
            throw new CurrencyExponentLocked($this->code->value);
        }

        $this->exponent = $exponent;
        $this->markChanged('exponent');
    }

    public function rename(TranslatedText $name): void
    {
        if (! $this->name->equals($name)) {
            $this->name = $name;
            $this->markChanged('name');
        }
    }

    public function changeAbbreviation(TranslatedText $abbreviation): void
    {
        if (! $this->abbreviation->equals($abbreviation)) {
            $this->abbreviation = $abbreviation;
            $this->markChanged('abbreviation');
        }
    }

    /**
     * @param  string|null  $sign  null clears the sign so prices fall back to the abbreviation
     */
    public function changeSign(?string $sign): void
    {
        self::assertSign($sign);

        if ($this->sign !== $sign) {
            $this->sign = $sign;
            $this->markChanged('sign');
        }
    }

    /**
     * @return list<string>
     */
    public function pullChanges(): array
    {
        [$changed, $this->changed] = [$this->changed, []];

        return $changed;
    }

    public function code(): CurrencyCode
    {
        return $this->code;
    }

    public function exponent(): int
    {
        return $this->exponent;
    }

    public function name(): TranslatedText
    {
        return $this->name;
    }

    public function abbreviation(): TranslatedText
    {
        return $this->abbreviation;
    }

    public function sign(): ?string
    {
        return $this->sign;
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }

    private static function assertExponent(int $exponent): void
    {
        if ($exponent < 0 || $exponent > self::MAX_EXPONENT) {
            throw new InvalidCurrencyAttribute('exponent', 'expected a number between 0 and '.self::MAX_EXPONENT);
        }
    }

    private static function assertSign(?string $sign): void
    {
        if ($sign !== null && (mb_strlen($sign, 'UTF-8') !== 1 || strlen($sign) > 8)) {
            throw new InvalidCurrencyAttribute('sign', 'expected exactly one character, or none');
        }
    }
}
