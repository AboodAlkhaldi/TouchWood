<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\PopupClash;
use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Exception\ThemeClash;
use Shared\Domain\Error\DomainError;

/**
 * A change refused on its page (content.md §4.2: "refusals under their box, others at the top"): a box's refusal lands
 * on that box when the form has it - a rule broken, English missing, an image not ready, an address taken, a clash on
 * the dates - and anything else goes in the form's error at the top (`FormErrors`). What was typed stays on the screen.
 */
final class Refusals
{
    /**
     * @param  list<string>  $boxes  the form's boxes, by the names it posts
     */
    public static function back(Request $request, DomainError $error, array $boxes): RedirectResponse
    {
        $box = self::box($error);

        if ($box !== null && in_array($box, $boxes, true)) {
            return back()->withErrors([$box => FormErrors::message($error)]);
        }

        return FormErrors::back($request, $error);
    }

    /**
     * As back(), to another page: a new item saved as a draft whose Schedule was then refused goes to its own form, which
     * now exists, with the refusal.
     *
     * @param  list<string>  $boxes
     */
    public static function to(string $url, DomainError $error, array $boxes): RedirectResponse
    {
        $box = self::box($error);
        $key = $box !== null && in_array($box, $boxes, true) ? $box : 'form';

        return redirect($url)->withErrors([$key => FormErrors::message($error)]);
    }

    /** The box a refusal is about, by the name the forms post. */
    private static function box(DomainError $error): ?string
    {
        return match (true) {
            $error instanceof InvalidContentInput => $error->attribute,
            $error instanceof MissingEnglish => "{$error->attribute}_en",
            $error instanceof ImageNotReady => $error->image,
            $error instanceof SlugTaken => "slug_{$error->locale}",
            $error instanceof PopupClash => 'starts_at',
            // A theme's clash is said under its dates (§1.8, W5b).
            $error instanceof ThemeClash => 'starts_at',
            // A redirect's rule names which of its two addresses it refuses; a line taken is its old address.
            $error instanceof RedirectInvalid => str_starts_with($error->rule, 'new_') ? 'new_path' : 'old_path',
            default => null,
        };
    }
}
