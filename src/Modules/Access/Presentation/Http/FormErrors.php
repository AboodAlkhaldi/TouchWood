<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Shared\Domain\Error\DomainError;

/**
 * The sign-in endpoints answer with redirects (amendment 12): a refused form goes back with its
 * error, in the person's language. A client asking for JSON gets the usual problem response.
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
