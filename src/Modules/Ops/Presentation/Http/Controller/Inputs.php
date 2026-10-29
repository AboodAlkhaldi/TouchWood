<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\Request;

/**
 * A request's values as Ops' handlers take them: a value of the wrong type is no value - the handler
 * then refuses it with its own error - never a 500.
 */
final class Inputs
{
    /** A text, trimmed; none when absent or empty. */
    public static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key) ?? $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A text as it was sent, or an empty one: the handler refuses what is empty. */
    public static function string(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    /** A box ticked: true, 1, "1", "true" or "on". */
    public static function flag(Request $request, string $key): bool
    {
        return in_array($request->input($key), [true, 1, '1', 'true', 'on'], true);
    }
}
