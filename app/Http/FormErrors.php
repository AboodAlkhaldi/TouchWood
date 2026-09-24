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
 *
 * **Where each kind of refusal is answered** (owner, 2026-09-24):
 *
 * - Somebody **going** somewhere that is not theirs - a link followed, an address typed - meets the
 *   403 page. They are not doing anything; the page is the whole answer, and ProblemDetails renders
 *   it. A page builder that refuses simply throws, and that is what happens.
 * - Somebody **doing** something on a screen they are already reading meets a message in that
 *   screen, and that includes being refused on permission. They pressed something and they are
 *   waiting; replacing the page under them answers the question but throws away everything else
 *   they had typed. So every refusal that arrives here is answered here - `Unauthorized` included.
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
        $translated = trans($key, self::values($module, $error->context()));

        return is_string($translated) && $translated !== $key ? $translated : $error->getMessage();
    }

    /**
     * The values a message puts in its gaps, as words rather than as code.
     *
     * A refusal that names a field carries the field's **key** - "current_password", "date_of_birth"
     * - because that is what the domain calls it and what the form posted. Dropped into a sentence
     * unchanged it reads as "The current_password is not valid", which is the inside of the system
     * showing through. Every other value is already a word, a number or a name and is left alone.
     *
     * @param  array<string, string|int|float|bool|null>  $context
     * @return array<string, string>
     */
    private static function values(string $module, array $context): array
    {
        $values = [];

        foreach ($context as $name => $value) {
            $values[$name] = in_array($name, ['attribute', 'field'], true)
                ? self::fieldName($module, (string) $value)
                : (string) $value;
        }

        return $values;
    }

    /**
     * A field's name in the person's language, from the module that named it.
     *
     * A field nobody has written down yet falls back to its own key with the underscores taken out:
     * "current password" is poor, and still better than "current_password" - and a missing line in
     * a language file must never be the reason a person cannot read why they were refused.
     */
    private static function fieldName(string $module, string $field): string
    {
        $key = $module === 'shared' ? "errors.fields.{$field}" : "{$module}::errors.fields.{$field}";
        $translated = trans($key);

        return is_string($translated) && $translated !== $key ? $translated : str_replace('_', ' ', $field);
    }
}
