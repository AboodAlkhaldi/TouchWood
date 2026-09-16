<?php

namespace Shared\Application;

use Shared\Domain\ValueObject\StoreId;

/**
 * Which store the current request, job or command is running in. Implemented by Platform.
 */
interface StoreContext
{
    /**
     * @throws MissingStoreContext when no store has been set
     */
    public function current(): StoreId;

    public function has(): bool;

    /**
     * Runs the callback in the given store, then restores whatever context was active before.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runIn(StoreId $store, callable $callback): mixed;
}
