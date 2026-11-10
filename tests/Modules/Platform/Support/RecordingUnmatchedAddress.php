<?php

declare(strict_types=1);

namespace Tests\Modules\Platform\Support;

use Illuminate\Http\RedirectResponse;
use Modules\Platform\Public\Contracts\UnmatchedAddress;

/**
 * A stand-in for Content's redirect lookup (content.md §2.3 row L2): answers the paths it is given a target
 * for, and records every path it was asked about. Reset by each test that uses it.
 */
final class RecordingUnmatchedAddress implements UnmatchedAddress
{
    /** @var list<string> */
    public static array $asked = [];

    /** @var array<string, string> path => where it now lives */
    public static array $targets = [];

    public static function reset(): void
    {
        self::$asked = [];
        self::$targets = [];
    }

    public function answer(string $path): ?RedirectResponse
    {
        self::$asked[] = $path;

        return isset(self::$targets[$path]) ? new RedirectResponse(self::$targets[$path], 301) : null;
    }
}
