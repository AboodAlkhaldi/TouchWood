<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Model;

use Modules\Platform\Domain\Exception\InvalidStoreAttribute;
use Modules\Platform\Domain\ValueObject\CountryCode;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Domain\ValueObject\TaxRate;
use Modules\Platform\Domain\ValueObject\Timezone;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Shared\Domain\ValueObject\StoreId;

/**
 * A country storefront (Platform spec §1.1).
 *
 * Created complete, never deleted, and with no lifecycle: code, country and currency are
 * fixed at creation, so this class offers no way to change them.
 */
final class Store
{
    private const int MAX_POSITION = 32767;

    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly StoreId $id,
        private readonly StoreCode $code,
        private TranslatedText $name,
        private readonly CountryCode $country,
        private readonly CurrencyCode $currency,
        private TaxRate $taxRate,
        private Timezone $timezone,
        private int $position,
    ) {
        self::assertPosition($position);
    }

    public static function create(
        StoreId $id,
        StoreCode $code,
        TranslatedText $name,
        CountryCode $country,
        CurrencyCode $currency,
        TaxRate $taxRate,
        Timezone $timezone,
        int $position,
    ): self {
        return new self($id, $code, $name, $country, $currency, $taxRate, $timezone, $position);
    }

    /**
     * Rebuilds a store from storage. Not a creation: nothing about it is new.
     */
    public static function reconstitute(
        StoreId $id,
        StoreCode $code,
        TranslatedText $name,
        CountryCode $country,
        CurrencyCode $currency,
        TaxRate $taxRate,
        Timezone $timezone,
        int $position,
    ): self {
        return new self($id, $code, $name, $country, $currency, $taxRate, $timezone, $position);
    }

    public function rename(TranslatedText $name): void
    {
        if (! $this->name->equals($name)) {
            $this->name = $name;
            $this->markChanged('name');
        }
    }

    /**
     * Affects only quotes computed afterwards; orders keep their own snapshot.
     */
    public function changeTaxRate(TaxRate $taxRate): void
    {
        if ($this->taxRate->basisPoints !== $taxRate->basisPoints) {
            $this->taxRate = $taxRate;
            $this->markChanged('tax_rate_basis_points');
        }
    }

    public function changeTimezone(Timezone $timezone): void
    {
        if ($this->timezone->identifier !== $timezone->identifier) {
            $this->timezone = $timezone;
            $this->markChanged('timezone');
        }
    }

    public function reposition(int $position): void
    {
        self::assertPosition($position);

        if ($this->position !== $position) {
            $this->position = $position;
            $this->markChanged('position');
        }
    }

    /**
     * The attributes changed since the store was loaded, cleared once read.
     *
     * @return list<string>
     */
    public function pullChanges(): array
    {
        [$changed, $this->changed] = [$this->changed, []];

        return $changed;
    }

    public function id(): StoreId
    {
        return $this->id;
    }

    public function code(): StoreCode
    {
        return $this->code;
    }

    public function name(): TranslatedText
    {
        return $this->name;
    }

    public function country(): CountryCode
    {
        return $this->country;
    }

    public function currency(): CurrencyCode
    {
        return $this->currency;
    }

    public function taxRate(): TaxRate
    {
        return $this->taxRate;
    }

    public function timezone(): Timezone
    {
        return $this->timezone;
    }

    public function position(): int
    {
        return $this->position;
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }

    private static function assertPosition(int $position): void
    {
        if ($position < 0 || $position > self::MAX_POSITION) {
            throw new InvalidStoreAttribute('position', 'expected a number between 0 and '.self::MAX_POSITION);
        }
    }
}
