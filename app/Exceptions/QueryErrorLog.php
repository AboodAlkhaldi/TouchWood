<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Psr\Log\LoggerInterface;

/**
 * A failed query is logged without its values (owner's decision, 2026-09-19), so no password hash
 * or personal data reaches the log file. The connection already writes "?" for each bound value
 * (mask_bindings_in_exception_messages). PostgreSQL repeats values in its own words, taken out
 * here: the DETAIL line ("Failing row contains (…)", "Key (email)=(…) already exists"), the CONTEXT
 * line ("unnamed portal parameter $1 = '…'"), and a value it could not read, which ends the error
 * line ("invalid input syntax for type date: "…"", review of step 3b).
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
        $message = (string) preg_replace('/\s*(?:DETAIL|CONTEXT):.*?(?=\s(?:DETAIL:|CONTEXT:|HINT:|\(Connection:)|\z)/s', '', $message);

        return (string) preg_replace('/: ".*?"(?=\s(?:HINT:|\(Connection:)|\z)/s', ': "…"', $message);
    }
}
