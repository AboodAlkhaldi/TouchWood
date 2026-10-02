<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Model;

use Modules\Platform\Domain\Exception\BaseStoreAlwaysActive;
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
 * Created complete and never deleted: code, country and currency are fixed at creation, so this
 * class offers no way to change them.
 *
 * **On or off** (owner, 2026-10-01; §1.1, §4.2): a store is created off, and only a Super Admin turns
 * it either way. **The base store** (owner, 2026-10-02) is always on: turning it off is refused here,
 * before the database's CHECK would refuse it. The base mark itself is set by the migration and the
 * seed and never moves, so this class offers no way to set or clear it.
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
        private bool $active,
        private readonly bool $base,
    ) {
        self::assertPosition($position);

        if ($base && ! $active) {
            throw new BaseStoreAlwaysActive($code->value);
        }
    }

    /**
     * A new store is created **off** (owner, 2026-10-01): a Super Admin turns it on once its
     * products, prices and stock are in. It is never the base store.
     */
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
        return new self($id, $code, $name, $country, $currency, $taxRate, $timezone, $position, active: false, base: false);
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
        bool $active,
        bool $base,
    ): self {
        return new self($id, $code, $name, $country, $currency, $taxRate, $timezone, $position, $active, $base);
    }

    /**
     * Turns the store on. A store already on is left as it is, and nothing is recorded as changed.
     */
    public function activate(): void
    {
        if (! $this->active) {
            $this->active = true;
            $this->markChanged('is_active');
        }
    }

    /**
     * Turns the store off. The base store is always on (owner, 2026-10-02).
     *
     * @throws BaseStoreAlwaysActive
     */
    public function deactivate(): void
    {
        if ($this->base) {
            throw new BaseStoreAlwaysActive($this->code->value);
        }

        if ($this->active) {
            $this->active = false;
            $this->markChanged('is_active');
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isBase(): bool
    {
        return $this->base;
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
