<?php

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Platform\Domain\Exception\StoreCodeTaken;
use Modules\Platform\Domain\Model\Store;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\CountryCode;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Domain\ValueObject\TaxRate;
use Modules\Platform\Domain\ValueObject\Timezone;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Shared\Domain\ValueObject\StoreId;

final class EloquentStoreRepository implements StoreRepository
{
    public function nextId(): StoreId
    {
        return StoreId::fromString((string) Str::ulid());
    }

    public function byCode(StoreCode $code): ?Store
    {
        $record = StoreRecord::query()->where('code', $code->value)->lockForUpdate()->first();

        return $record === null ? null : Store::reconstitute(
            StoreId::fromString($record->id),
            StoreCode::fromString($record->code),
            TranslatedText::of($record->name['ar'], $record->name['en'], 'name'),
            CountryCode::fromString($record->country_code),
            CurrencyCode::fromString($record->currency_code),
            TaxRate::fromBasisPoints($record->tax_rate_basis_points),
            Timezone::fromString($record->timezone),
            $record->position,
        );
    }

    public function codeExists(StoreCode $code): bool
    {
        return StoreRecord::query()->where('code', $code->value)->exists();
    }

    public function add(Store $store): void
    {
        try {
            StoreRecord::query()->create([
                'id' => $store->id()->value,
                'code' => $store->code()->value,
                'country_code' => $store->country()->value,
                'currency_code' => $store->currency()->value,
                ...$this->mutableAttributes($store),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new StoreCodeTaken($store->code()->value);
        }
    }

    public function update(Store $store): void
    {
        StoreRecord::query()->whereKey($store->id()->value)->firstOrFail()->update($this->mutableAttributes($store));
    }

    /**
     * Code, country and currency are written once, by add(), and never again.
     *
     * @return array<string, mixed>
     */
    private function mutableAttributes(Store $store): array
    {
        return [
            'name' => ['ar' => $store->name()->ar, 'en' => $store->name()->en],
            'tax_rate_basis_points' => $store->taxRate()->basisPoints,
            'timezone' => $store->timezone()->identifier,
            'position' => $store->position(),
        ];
    }
}
