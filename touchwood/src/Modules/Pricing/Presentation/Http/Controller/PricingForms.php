<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use ReflectionClass;
use Shared\Domain\Error\DomainError;
use Spatie\LaravelData\Data;

/**
 * What Pricing's screens share on the server (pricing.md §4.3.1, stage 5's screens step 3): a change done
 * and said in a toast, or refused where the person is looking - beside the field it names when the form has
 * one, else at the top of the dialog or page and as a toast, and for a batch on each row it names (Q2,
 * `App\Http\FormErrors`); a request's values read as typed; a page's data read only when the page asks for
 * it (a partial visit - Show More, the drawer - asks for its own props alone).
 */
final class PricingForms
{
    /**
     * Does the work and answers with the toast that says it was done, or with the refusal.
     *
     * @param  Closure(): mixed  $work
     * @param  list<string>  $fields  the form's own fields, where a refusal naming one is said
     * @param  string|null  $to  where a success goes, when the page it came from is gone (a discount removed)
     */
    public static function act(Request $request, Closure $work, string $done, array $fields = [], ?string $to = null): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            if ($error instanceof InvalidPricingInput && $error->variants === [] && in_array($error->attribute, $fields, true) && ! $request->expectsJson()) {
                return back()->withErrors([$error->attribute => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        }

        return ($to === null ? back() : redirect($to))->with('status', __($done));
    }

    /**
     * A page's props, each read only when the page asks for it: the page's data is made once, the first
     * time any of them is read - so a partial visit for the drawer does not read the rows again.
     *
     * @param  class-string<Data>  $page
     * @param  Closure(): Data  $make
     * @return array<string, Closure(): mixed>
     */
    public static function lazy(string $page, Closure $make): array
    {
        $made = null;
        $once = static function () use (&$made, $make): array {
            return $made ??= $make()->toArray();
        };
        $props = [];

        foreach ((new ReflectionClass($page))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $props[$name] = static fn (): mixed => $once()[$name] ?? null;
        }

        return $props;
    }

    /** A value as typed, its ends trimmed; nothing typed is null. */
    public static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A list as sent, only its texts.
     *
     * @return list<string>
     */
    public static function texts(Request $request, string $key): array
    {
        $value = $request->input($key);

        return is_array($value) ? array_values(array_filter(array_map(static fn (mixed $each): ?string => is_string($each) ? trim($each) : null, $value), static fn (?string $each): bool => $each !== null && $each !== '')) : [];
    }

    /**
     * A map as sent - a size's id to the price typed - only its texts.
     *
     * @return array<string, string>
     */
    public static function map(Request $request, string $key): array
    {
        $value = $request->input($key);
        $map = [];

        foreach (is_array($value) ? $value : [] as $id => $text) {
            if (is_string($text) || is_int($text) || is_float($text)) {
                $map[(string) $id] = trim((string) $text);
            }
        }

        return $map;
    }

    public static function flag(Request $request, string $key): bool
    {
        return $request->boolean($key);
    }

    /** A query value as typed, upper-cased: a state, an order, a filter. */
    public static function word(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null;
    }

    /** A query value as typed. */
    public static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
