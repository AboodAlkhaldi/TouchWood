<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\NoticeAlreadyStarted;
use Shared\Domain\Error\DomainError;

/**
 * A notice or a message refused on its page (ops.md §4.15 O4, O6): a box's refusal lands on that box, a segment gone or a
 * notice started goes in the form's error with a mark the page acts on - it empties the picker, it offers the way back.
 */
final class WrittenRefusals
{
    /** @var list<string> */
    private const array BOXES = ['title_ar', 'title_en', 'body_ar', 'body_en', 'channels', 'customer', 'audience', 'link', 'send_at'];

    private const string SEGMENT_GONE = 'promotions.segment_not_found';

    public static function back(Request $request, DomainError $error): RedirectResponse
    {
        if ($error instanceof InvalidOpsInput && in_array($error->attribute, self::BOXES, true)) {
            return back()->withErrors([$error->attribute => FormErrors::message($error)]);
        }

        $mark = match (true) {
            $error->type() === self::SEGMENT_GONE => 'segment_gone',
            $error instanceof NoticeAlreadyStarted => 'started',
            default => null,
        };

        // One call: a second withErrors would replace the first's bag.
        return $mark === null ? FormErrors::back($request, $error) : back()->withErrors(['form' => FormErrors::message($error), $mark => '1']);
    }
}
