<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Illuminate\Database\Query\Builder;
use Modules\B2B\Domain\ValueObject\TypeName;

/**
 * "Is this name already some other type's?" — asked the same way of both type tables, and the same
 * way their unique indexes decide it: lower() in PostgreSQL, in either language (owner,
 * 2026-09-27). Comparing in PHP instead would let the two disagree about a letter PHP and the
 * database fold differently.
 */
final class TypeNames
{
    public static function taken(Builder $table, TypeName $name, ?string $exceptId): bool
    {
        return $table
            ->where(static fn (Builder $query) => $query
                ->whereRaw('lower(name_ar) = lower(?)', [$name->ar])
                ->orWhereRaw('lower(name_en) = lower(?)', [$name->en]))
            ->when($exceptId !== null, static fn (Builder $query) => $query->where('id', '<>', strtolower((string) $exceptId)))
            ->exists();
    }
}
