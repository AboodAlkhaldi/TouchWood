<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use ReflectionClass;
use Shared\Domain\Error\DomainError;
use Spatie\LaravelData\Data;

/**
 * What Sync's screens share on the server (sync.md §4.1, as pricing.md §4.3.1): a change done and said in
 * a toast, or refused where the person is looking - a refused field of the Provider's form also under its
 * own box (`errors.address`, `errors.key`, `errors.secret`, `errors["settings.<name>"]`, Q34); a request's
 * values read as typed; a page's data read only when the page asks for it.
 */
final class SyncForms
{
    /** The Provider form's own boxes: a refusal naming one of them is said under it too. */
    private const array BOXES = ['provider', 'address', 'key', 'secret'];

    /** What a refused form keeps as typed - never the key or the secret. */
    private const array KEEP = ['provider', 'address', 'settings'];

    /**
     * Does the work and answers with the toast that says it was done, or with the refusal.
     *
     * @param  Closure(): mixed  $work
     */
    public static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            $box = self::box($error);

            if ($box === null || $request->expectsJson()) {
                return FormErrors::back($request, $error, self::KEEP);
            }

            $message = FormErrors::message($error);

            return redirect()->back()->withInput($request->only(self::KEEP))->withErrors(['form' => $message, $box => $message]);
        }

        return back()->with('status', __($done));
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

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A query value as typed. */
    public static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The provider's settings as sent, each as typed - a value that is not text kept as it came, for the
     * handler to refuse by its name.
     *
     * @return array<array-key, mixed>
     */
    public static function settings(Request $request): array
    {
        $settings = $request->input('settings');

        return is_array($settings) ? $settings : [];
    }

    /** The Provider form's box a refusal names, if any. */
    private static function box(DomainError $error): ?string
    {
        if (! $error instanceof InvalidSyncInput) {
            return null;
        }

        if ($error->setting !== null) {
            return 'settings.'.$error->setting;
        }

        return in_array($error->attribute, self::BOXES, true) ? $error->attribute : null;
    }
}
