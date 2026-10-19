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

        $message = self::message($error);
        $errors = ['form' => $message];

        foreach (array_keys(self::rows($error, $message)) as $row) {
            $errors["rows.{$row}"] = $message;
        }

        return redirect()->back()->withInput($request->only($keep))->withErrors($errors);
    }

    /**
     * **The rows a batch refusal concerns** (pricing.md §4.3.1, Q2): a command that saves many sizes
     * at once - prices, counts - refuses them all, and its error names the sizes it is about in its
     * `variants` context, comma-separated. Each is passed to the page as `errors["rows.{id}"]`, beside
     * `errors.form`, so the screen marks those rows - their message under their box - and keeps what
     * was typed in the others. A refusal naming no size has none.
     *
     * Flat keys, not a nested `errors.rows`: Inertia hands a page the first message of each key, and a
     * key holding a map of messages has no first one (`resources/js/lib/row-errors.ts` reads them back).
     *
     * @return array<string, string> size id => its message
     */
    public static function rows(DomainError $error, ?string $message = null): array
    {
        $named = $error->context()['variants'] ?? null;

        if (! is_string($named) || trim($named) === '') {
            return [];
        }

        $message ??= self::message($error);
        $rows = [];

        foreach (explode(',', $named) as $id) {
            $id = trim($id);

            // An id is a size's: letters and digits only, so it can never break the key it is put in.
            if (preg_match('/\A[0-9A-Za-z]{1,64}\z/', $id) === 1) {
                $rows[$id] = $message;
            }
        }

        return $rows;
    }

    /**
     * "access.invalid_credentials" → access::errors.invalid_credentials.detail, with its values.
     *
     * A refusal that names **the rule it broke** in its `rule` context - Pricing's wholesale bands (pricing.md
     * §7, owner Q12) - says that rule's own words, `….rules.{rule}`, where the module wrote them; the error's
     * one detail otherwise.
     */
    public static function message(DomainError $error): string
    {
        [$module, $name] = str_contains($error->type(), '.') ? explode('.', $error->type(), 2) : ['shared', $error->type()];
        $base = $module === 'shared' ? "errors.{$name}" : "{$module}::errors.{$name}";
        $values = self::values($module, $error->context());
        $rule = self::ruleKey($base, $error->context());

        if ($rule !== null) {
            $translated = trans($rule, $values);

            if (is_string($translated) && $translated !== $rule) {
                return $translated;
            }
        }

        $translated = trans("{$base}.detail", $values);

        return is_string($translated) && $translated !== "{$base}.detail" ? $translated : $error->getMessage();
    }

    /**
     * The key of the words for the rule a refusal names, or null when it names none.
     *
     * @param  array<string, string|int|float|bool|null>  $context
     */
    public static function ruleKey(string $base, array $context): ?string
    {
        $rule = $context['rule'] ?? null;

        return is_string($rule) && preg_match('/\A[a-z_]{1,40}\z/', $rule) === 1 ? "{$base}.rules.{$rule}" : null;
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
