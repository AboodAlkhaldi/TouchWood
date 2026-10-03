<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Shared\Domain\Error\DomainError;

/**
 * Where a refusal on one of B2B's staff screens is said (frontend.md §2.1): beside the field it names
 * when the screen has that field — a reason, a name, a position — and otherwise at the top of the page
 * and in a toast, in the module's own words (§7). Always on the screen the person pressed something
 * on, never a page that replaces it (App\Http\FormErrors).
 */
final class StaffRefusals
{
    /**
     * @param  array<string, string>  $fields  the domain's attribute => the screen's field
     */
    public static function back(Request $request, DomainError $error, array $fields = []): RedirectResponse
    {
        if ($error instanceof InvalidCompanyAttribute && isset($fields[$error->attribute]) && ! $request->expectsJson()) {
            return back()->withErrors([$fields[$error->attribute] => FormErrors::message($error)]);
        }

        return FormErrors::back($request, $error);
    }

    /**
     * A refusal that belongs to one field whatever it is — a correction to a deactivated type, said
     * beside the type chosen.
     */
    public static function on(Request $request, DomainError $error, string $field): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw $error;
        }

        return back()->withErrors([$field => FormErrors::message($error)]);
    }
}
