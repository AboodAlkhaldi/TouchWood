<?php

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Domain\Exception\CurrencyAlreadyExists;
use Modules\Platform\Domain\Model\Currency;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\TranslatedText;

final class EloquentCurrencyRepository implements CurrencyRepository
{
    public function byCode(CurrencyCode $code): ?Currency
    {
        $record = CurrencyRecord::query()->whereKey($code->value)->lockForUpdate()->first();

        return $record === null ? null : Currency::reconstitute(
            CurrencyCode::fromString($record->code),
            $record->exponent,
            TranslatedText::of($record->name['ar'], $record->name['en'], 'name'),
            TranslatedText::of($record->abbreviation['ar'], $record->abbreviation['en'], 'abbreviation'),
            $record->sign,
        );
    }

    public function exists(CurrencyCode $code): bool
    {
        return CurrencyRecord::query()->whereKey($code->value)->exists();
    }

    public function isUsedByAnyStore(CurrencyCode $code): bool
    {
        return StoreRecord::query()->where('currency_code', $code->value)->exists();
    }

    public function add(Currency $currency): void
    {
        try {
            CurrencyRecord::query()->create(['code' => $currency->code()->value, ...$this->attributes($currency)]);
        } catch (UniqueConstraintViolationException) {
            throw new CurrencyAlreadyExists($currency->code()->value);
        }
    }

    public function update(Currency $currency): void
    {
        CurrencyRecord::query()->whereKey($currency->code()->value)->firstOrFail()->update($this->attributes($currency));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Currency $currency): array
    {
        return [
            'exponent' => $currency->exponent(),
            'name' => ['ar' => $currency->name()->ar, 'en' => $currency->name()->en],
            'abbreviation' => ['ar' => $currency->abbreviation()->ar, 'en' => $currency->abbreviation()->en],
            'sign' => $currency->sign(),
        ];
    }
}
