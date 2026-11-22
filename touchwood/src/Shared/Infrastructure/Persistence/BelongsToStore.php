<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shared\Application\CrossStoreWrite;
use Shared\Application\StoreContext;

/**
 * For Eloquent models whose rows belong to one store (handoff §4.1).
 *
 * Reads, updates and deletes through the query builder are limited to the current store. Model
 * writes are guarded too: a new row is stamped with the current store and cannot name another
 * one, a row's store_id never changes, and a row of another store cannot be saved or deleted —
 * even one loaded through acrossStores(). With no current store, all of these throw.
 *
 * Reading across stores is the explicit acrossStores() opt-out, allowed only in
 * Application/Query read models and the Ops module; tests/Architecture enforces where it may be
 * called. Raw insert() and upsert() skip model events entirely, so store-scoped rows must be
 * created through the model.
 *
 * @mixin Model
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);

        static::creating(function (Model $model): void {
            $current = app(StoreContext::class)->current()->value;
            $rowStore = $model->getAttribute('store_id');

            if ($rowStore === null) {
                $model->setAttribute('store_id', $current);
            } elseif ($rowStore !== $current) {
                throw CrossStoreWrite::create($model->getTable(), (string) $rowStore, $current);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('store_id')) {
                throw CrossStoreWrite::move($model->getTable());
            }

            self::assertBelongsToCurrentStore($model);
        });

        static::deleting(fn (Model $model) => self::assertBelongsToCurrentStore($model));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAcrossStores(Builder $query): Builder
    {
        return $query->withoutGlobalScope(StoreScope::class);
    }

    private static function assertBelongsToCurrentStore(Model $model): void
    {
        $current = app(StoreContext::class)->current()->value;
        $rowStore = (string) $model->getOriginal('store_id');

        if ($rowStore !== $current) {
            throw CrossStoreWrite::create($model->getTable(), $rowStore, $current);
        }
    }
}
