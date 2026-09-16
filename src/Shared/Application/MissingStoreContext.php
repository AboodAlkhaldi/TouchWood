<?php

namespace Shared\Application;

use LogicException;

/**
 * Store-scoped work was attempted with no current store. Always a programming error: it is
 * never shown to a customer and is never a DomainError.
 */
final class MissingStoreContext extends LogicException
{
    public static function create(): self
    {
        return new self('No store context is set. Store-scoped data cannot be read or written without one.');
    }
}
