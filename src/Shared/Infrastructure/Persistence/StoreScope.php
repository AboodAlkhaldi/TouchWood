<?php

namespace Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Shared\Application\StoreContext;

/**
 * Limits every query to the current store. With no current store the query throws
 * MissingStoreContext — it never falls back to every store's rows.
 *
 * The context is resolved on each query, not when the model boots, because a model class
 * boots once per process while the current store changes per request and per job.
 *
 * @implements Scope<Model>
 */
final class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('store_id'), app(StoreContext::class)->current()->value);
    }
}
