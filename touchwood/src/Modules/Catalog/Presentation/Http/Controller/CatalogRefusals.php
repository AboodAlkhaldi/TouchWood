<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Domain\Error\DomainError;

/**
 * Where a refusal on one of Catalog's screens is said (frontend.md §2.1): beside the field it names
 * when the form has that field — the domain names fields as the forms do (`name_ar`, `slug_en`,
 * `position` …) — and otherwise at the top of the form or the dialog, and in a toast, in the module's
 * own words (catalog.md §7). Always on the screen the person pressed something on, never a page that
 * replaces it (App\Http\FormErrors).
 */
final class CatalogRefusals
{
    /**
     * Does the work and answers with the toast that says it was done, or with the refusal.
     *
     * @param  Closure(): mixed  $work
     * @param  list<string>  $fields  the form's own fields, where a refusal naming one is said
     */
    public static function act(Request $request, Closure $work, string $done, array $fields = []): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            return self::back($request, $error, $fields);
        }

        return back()->with('status', __($done));
    }

    /**
     * A photo sent with a form that did not arrive whole - most often one larger than the server
     * accepts, which PHP drops before any handler sees it - refused beside its field, never a 500
     * (as Platform's own upload screen does, MediaController::upload). Null when there is none, or
     * it arrived.
     */
    public static function fileNotArrived(Request $request, string $file, string $field): ?RedirectResponse
    {
        $sent = $request->file($file);

        if ($sent === null || ($sent instanceof UploadedFile && $sent->isValid() && $sent->getRealPath() !== false)) {
            return null;
        }

        return back()->withErrors([$field => __('catalog::admin.image.not_arrived')]);
    }

    /**
     * @param  list<string>  $fields
     */
    public static function back(Request $request, DomainError $error, array $fields = []): RedirectResponse
    {
        if ($error instanceof InvalidCatalogAttribute && in_array($error->attribute, $fields, true) && ! $request->expectsJson()) {
            return back()->withErrors([$error->attribute => FormErrors::message($error)]);
        }

        return FormErrors::back($request, $error);
    }
}
