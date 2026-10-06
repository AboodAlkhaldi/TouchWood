<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Platform\Domain\Exception\CurrencyTaken;
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
            $record->is_active,
            $record->is_base,
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
                // Written once, here, and never by update(): the mark is set by the migration and
                // the seed and does not move (platform.md §1.1, §5.2).
                'is_base' => $store->isBase(),
                ...$this->mutableAttributes($store),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Named by the index refused, so a race for a free currency is not told its code is taken
            // (one currency, one store: §9.7 #4).
            throw match (true) {
                str_contains($e->getMessage(), 'stores_one_per_currency') => new CurrencyTaken($store->currency()->value),
                str_contains($e->getMessage(), 'platform_stores_code_unique') => new StoreCodeTaken($store->code()->value),
                default => $e,
            };
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
            'is_active' => $store->isActive(),
        ];
    }
}
