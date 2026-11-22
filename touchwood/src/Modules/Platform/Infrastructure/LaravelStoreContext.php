<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Illuminate\Support\Facades\Context;
use Shared\Application\MissingStoreContext;
use Shared\Application\StoreContext;
use Shared\Domain\ValueObject\StoreId;

/**
 * Keeps the current store in Laravel's Context. Context is written into every queued job's
 * payload and restored when the job runs, so a job runs in the store it was dispatched
 * from without passing the store by hand (Platform spec §1.6). It also tags every log line.
 */
final class LaravelStoreContext implements StoreContext
{
    public const string CONTEXT_KEY = 'store_id';

    public function current(): StoreId
    {
        $value = Context::get(self::CONTEXT_KEY);

        if (! is_string($value)) {
            throw MissingStoreContext::create();
        }

        return StoreId::fromString($value);
    }

    public function has(): bool
    {
        return is_string(Context::get(self::CONTEXT_KEY));
    }

    public function runIn(StoreId $store, callable $callback): mixed
    {
        return Context::scope($callback, [self::CONTEXT_KEY => $store->value]);
    }

    /**
     * Sets the store for the rest of the request. Only store resolution calls this.
     */
    public function enter(StoreId $store): void
    {
        Context::add(self::CONTEXT_KEY, $store->value);
    }
}
