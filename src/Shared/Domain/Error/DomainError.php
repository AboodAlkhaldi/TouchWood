<?php

namespace Shared\Domain\Error;

use RuntimeException;

/**
 * The base of every expected business error: a rule was broken, or something was not found.
 *
 * Domain code chooses a category and never knows HTTP. The exception handler maps the
 * category to a status and renders one response shape for every module.
 */
abstract class DomainError extends RuntimeException
{
    /**
     * A stable machine key, "{module}.{error}" — e.g. "platform.store_code_taken".
     * The first segment is also the translation namespace for the error's messages.
     */
    abstract public function type(): string;

    abstract public function category(): ErrorCategory;

    /**
     * Values the translated message needs, e.g. ['code' => $code].
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function context(): array
    {
        return [];
    }
}
