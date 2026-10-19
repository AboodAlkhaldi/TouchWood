<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use ReflectionClass;
use Shared\Domain\Error\DomainError;
use Spatie\LaravelData\Data;

/**
 * What Inventory's screens share on the server (inventory.md §4.3.1, as pricing.md §4.3.1): a change done and
 * said in a toast - with a second, amber one when it left orders short - or refused where the person is
 * looking, a batch's refusal on each row it names (Q2, `App\Http\FormErrors`); a request's values read as
 * typed; a page's data read only when the page asks for it (a partial visit asks for its own props alone).
 */
final class InventoryForms
{
    /**
     * Does the work and answers with the toast that says it was done - and the amber one its answer asks
     * for - or with the refusal.
     *
     * @param  Closure(): (string|null)  $work  the amber toast's words, or null
     */
    public static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $warning = $work();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        $back = back()->with('status', __($done));

        return $warning === null ? $back : $back->with('warning', $warning);
    }

    /**
     * A page's props, each read only when the page asks for it: the page's data is made once, the first
     * time any of them is read.
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

        return is_string($value) && trim($value) !== '' ? trim($value) : (is_int($value) ? (string) $value : null);
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
     * A whole number as typed: digits only, else the given refusal value - the handler refuses it naming its
     * row, never a number the person did not type.
     */
    public static function whole(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        return preg_match('/\A[0-9]{1,10}\z/', $text) === 1 && (int) $text <= PHP_INT_MAX ? (int) $text : -1;
    }

    /** A query value as typed, upper-cased: a filter. */
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
