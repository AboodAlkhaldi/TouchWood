<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Psr\Log\LoggerInterface;

/**
 * A failed query is logged without its values (owner's decision, 2026-09-19), so no password hash
 * or personal data reaches the log file. The connection already writes "?" for each bound value
 * (mask_bindings_in_exception_messages); PostgreSQL adds a DETAIL line of its own — "Failing row
 * contains (…)", "Key (email)=(…) already exists" — which is taken out here.
 */
final class QueryErrorLog
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->report(function (QueryException $error): bool {
            app(LoggerInterface::class)->error(self::withoutValues($error->getMessage()), [
                'exception' => $error::class,
                'code' => $error->getCode(),
            ]);

            // Logged here instead of by Laravel, which would write the message as it is.
            return false;
        });
    }

    public static function withoutValues(string $message): string
    {
        return (string) preg_replace('/\s*DETAIL:.*?(?=\s\(Connection:|\z)/s', '', $message);
    }
}
