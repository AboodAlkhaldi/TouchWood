<?php

namespace Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shared\Application\StoreContext;

/**
 * For Eloquent models whose rows belong to one store (handoff §4.1).
 *
 * Queries are limited to the current store and new rows are stamped with it. Reading across
 * stores is the explicit acrossStores() opt-out, allowed only in Application/Query read
 * models and the Ops module; tests/Architecture enforces where it may be called.
 *
 * @mixin Model
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('store_id') === null) {
                $model->setAttribute('store_id', app(StoreContext::class)->current()->value);
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAcrossStores(Builder $query): Builder
    {
        return $query->withoutGlobalScope(StoreScope::class);
    }
}
