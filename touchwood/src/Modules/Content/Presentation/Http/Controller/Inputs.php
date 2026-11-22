<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\Request;

/**
 * A request's values as Content's handlers take them: a value of the wrong type is no value - the handler then refuses
 * it with its own error - never a 500.
 */
final class Inputs
{
    /** A text, trimmed; none when absent or empty. */
    public static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key) ?? $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A text as typed (a box's own spaces kept for its handler to judge), none when absent or empty. */
    public static function typed(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A box ticked: true, 1, "1", "true" or "on". */
    public static function flag(Request $request, string $key): bool
    {
        return in_array($request->input($key), [true, 1, '1', 'true', 'on'], true);
    }

    /** A whole number, or none; anything else is none, for the handler to refuse. */
    public static function number(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/\A-?\d{1,9}\z/', trim($value)) === 1 ? (int) trim($value) : null;
    }

    /** The version a form read, 0 for a new item. */
    public static function version(Request $request): int
    {
        return max(0, self::number($request, 'version') ?? 0);
    }

    /**
     * A list of texts, as JSON sends it or a form does (`ids[]`); anything that is not a text is left out.
     *
     * @return list<string>
     */
    public static function texts(Request $request, string $key): array
    {
        $value = $request->input($key);

        return is_array($value) ? array_values(array_filter($value, static fn (mixed $each): bool => is_string($each) && $each !== '')) : [];
    }

    /**
     * A map of texts (`images[DESKTOP:any]`); a value that is not a text reads as none.
     *
     * @return array<string, string|null>
     */
    public static function map(Request $request, string $key): array
    {
        $value = $request->input($key);
        $map = [];

        foreach (is_array($value) ? $value : [] as $name => $each) {
            $map[(string) $name] = is_string($each) && trim($each) !== '' ? trim($each) : null;
        }

        return $map;
    }
}
