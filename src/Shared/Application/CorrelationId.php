<?php

declare(strict_types=1);

namespace Shared\Application;

/**
 * Where the current request's correlation id is kept in Laravel's Context. The middleware that
 * assigns it (App\Http\Middleware\AssignCorrelationId) and the error renderer are framework glue
 * in app/; module code, such as the audit log, reads the id through this key.
 */
final class CorrelationId
{
    public const string CONTEXT_KEY = 'correlation_id';
}
