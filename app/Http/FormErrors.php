<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Shared\Domain\Error\DomainError;

/**
 * A refused form goes back with its error, in the person's language; a client asking for JSON gets
 * the usual problem response instead (beside ProblemDetails, which answers the same errors on a
 * page). Access's endpoints answer with redirects (its amendment 12) and every module's screens
 * answer the same way, so this is framework glue rather than one module's (stage 2b, P5).
 */
final class FormErrors
{
    /**
     * @param  list<string>  $keep  inputs to keep in the form — never a password or a code
     */
    public static function back(Request $request, DomainError $error, array $keep = []): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw $error;
        }

        return redirect()->back()->withInput($request->only($keep))->withErrors(['form' => self::message($error)]);
    }

    /**
     * "access.invalid_credentials" → access::errors.invalid_credentials.detail, with its values.
     */
    public static function message(DomainError $error): string
    {
        [$module, $name] = str_contains($error->type(), '.') ? explode('.', $error->type(), 2) : ['shared', $error->type()];
        $key = $module === 'shared' ? "errors.{$name}.detail" : "{$module}::errors.{$name}.detail";
        $translated = trans($key, array_map(fn (string|int|float|bool|null $value): string => (string) $value, $error->context()));

        return is_string($translated) && $translated !== $key ? $translated : $error->getMessage();
    }
}
