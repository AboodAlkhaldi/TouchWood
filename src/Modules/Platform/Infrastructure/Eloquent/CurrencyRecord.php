<?php

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistence only. Never leaves the Platform module; other modules receive CurrencyDto.
 *
 * @property string $code
 * @property int $exponent
 * @property array{ar: string, en: string} $name
 * @property array{ar: string, en: string} $abbreviation
 * @property string|null $sign
 */
final class CurrencyRecord extends Model
{
    protected $table = 'platform.currencies';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'exponent' => 'integer',
            'name' => 'array',
            'abbreviation' => 'array',
        ];
    }
}
