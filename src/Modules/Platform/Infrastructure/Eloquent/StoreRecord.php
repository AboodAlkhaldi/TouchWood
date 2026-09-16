<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistence only. Never leaves the Platform module; other modules receive StoreDto.
 *
 * Stores are global, not store-scoped, so this model does not use BelongsToStore.
 *
 * @property string $id
 * @property string $code
 * @property array{ar: string, en: string} $name
 * @property string $country_code
 * @property string $currency_code
 * @property int $tax_rate_basis_points
 * @property string $timezone
 * @property int $position
 */
final class StoreRecord extends Model
{
    protected $table = 'platform.stores';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'tax_rate_basis_points' => 'integer',
            'position' => 'integer',
        ];
    }
}
